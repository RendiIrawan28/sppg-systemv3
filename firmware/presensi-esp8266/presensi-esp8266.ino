/**
 * SPPG RFID Attendance
 * ESP8266 NodeMCU + MFRC522 + LCD I2C 16x2 + LED status
 *
 * Library:
 * - WiFiManager
 * - MFRC522
 * - LiquidCrystal_I2C
 *
 * Salin DEVICE_CODE dan DEVICE_KEY dari website: Presensi relawan > Perangkat.
 */

#include <ESP8266WiFi.h>
#include <ESP8266HTTPClient.h>
#include <WiFiManager.h>
#include <LittleFS.h>
#include <SPI.h>
#include <MFRC522.h>
#include <Wire.h>
#include <LiquidCrystal_I2C.h>
#include <time.h>

const String SERVER_URL = "http://202.155.19.32";
const String DEVICE_CODE = "PRESENSI_01";
const String DEVICE_KEY = "E07nn4prNreGQOVJHnJqnEWndfY4eEgtAtmlYys7";
const String FIRMWARE_VERSION = "1.0.0";

const char* WIFI_AP_NAME = "SPPG-PRESENSI-SETUP";
const char* WIFI_AP_PASSWORD = "12345678";

#define I2C_SCL_PIN 5
#define I2C_SDA_PIN 4
#define SS_RFID 3
#define RST_PIN 16
#define LED_HIJAU 2
#define LED_MERAH 0

LiquidCrystal_I2C lcd(0x27, 16, 2);
MFRC522 rfid(SS_RFID, RST_PIN);

const char* QUEUE_FILE = "/attendance.queue";
const char* NAME_CACHE_FILE = "/attendance.names";
unsigned long wifiDisconnectedSince = 0;
String lastUid = "";
unsigned long lastUidAt = 0;

String limitText(String text, int length = 16) {
  text.trim();
  return text.length() > length ? text.substring(0, length) : text;
}

void displayMessage(String line1, String line2) {
  lcd.backlight();
  lcd.clear();
  lcd.setCursor(0, 0);
  lcd.print(limitText(line1));
  lcd.setCursor(0, 1);
  lcd.print(limitText(line2));
}

void showIdle() {
  displayMessage("SISTEM SIAP", "Tap Kartu Anda");
  delay(250);
  lcd.noBacklight();
}

void ledResult(bool success) {
  digitalWrite(LED_HIJAU, HIGH);
  digitalWrite(LED_MERAH, HIGH);
  digitalWrite(success ? LED_HIJAU : LED_MERAH, LOW);
  delay(success ? 500 : 1000);
  digitalWrite(LED_HIJAU, HIGH);
  digitalWrite(LED_MERAH, HIGH);
}

String jsonValue(const String& json, const String& key) {
  String marker = "\"" + key + "\"";
  int keyAt = json.indexOf(marker);
  if (keyAt < 0) return "";
  int colonAt = json.indexOf(':', keyAt + marker.length());
  if (colonAt < 0) return "";
  int startAt = colonAt + 1;
  while (startAt < (int) json.length() && (json[startAt] == ' ' || json[startAt] == '\n')) startAt++;
  if (json[startAt] == '"') {
    startAt++;
    int endAt = json.indexOf('"', startAt);
    return endAt < 0 ? "" : json.substring(startAt, endAt);
  }
  int endAt = json.indexOf(',', startAt);
  if (endAt < 0) endAt = json.indexOf('}', startAt);
  String value = endAt < 0 ? "" : json.substring(startAt, endAt);
  value.trim();
  return value;
}

String urlEncode(const String& value) {
  String encoded = "";
  const char* hex = "0123456789ABCDEF";
  for (unsigned int i = 0; i < value.length(); i++) {
    char c = value.charAt(i);
    if (isalnum(c) || c == '-' || c == '_' || c == '.' || c == '~') encoded += c;
    else {
      encoded += '%';
      encoded += hex[(c >> 4) & 0xF];
      encoded += hex[c & 0xF];
    }
  }
  return encoded;
}

String isoTime(time_t timestamp) {
  if (timestamp < 1700000000) return "";
  struct tm* value = gmtime(&timestamp);
  char output[25];
  strftime(output, sizeof(output), "%Y-%m-%dT%H:%M:%SZ", value);
  return String(output);
}

String requestId(const String& uid) {
  return String(ESP.getChipId(), HEX) + "-" + String((unsigned long) time(nullptr)) + "-" + String(millis()) + "-" + uid;
}

void cacheName(const String& uid, const String& name) {
  if (name == "" || !LittleFS.begin()) return;
  File file = LittleFS.open(NAME_CACHE_FILE, "a");
  if (!file) return;
  file.println(uid + "|" + name);
  file.close();
}

String cachedName(const String& uid) {
  if (!LittleFS.begin()) return "";
  File file = LittleFS.open(NAME_CACHE_FILE, "r");
  String found = "";
  while (file && file.available()) {
    String line = file.readStringUntil('\n');
    line.trim();
    if (line.startsWith(uid + "|")) found = line.substring(uid.length() + 1);
  }
  if (file) file.close();
  return found;
}

void queueTap(const String& uid, const String& id, time_t tappedAt) {
  if (!LittleFS.begin()) return;
  File file = LittleFS.open(QUEUE_FILE, "a");
  if (!file) return;
  file.println(id + "|" + uid + "|" + String((unsigned long) tappedAt));
  file.close();
}

int sendTap(const String& uid, const String& id, time_t tappedAt, bool offline, String& response) {
  WiFiClient client;
  HTTPClient http;
  http.setTimeout(8000);
  http.begin(client, SERVER_URL + "/api/iot/attendance/tap");
  http.addHeader("Content-Type", "application/x-www-form-urlencoded");
  http.addHeader("Accept", "application/json");
  http.addHeader("X-Device-Code", DEVICE_CODE);
  http.addHeader("X-Device-Key", DEVICE_KEY);
  http.addHeader("X-Firmware-Version", FIRMWARE_VERSION);
  String body = "uid_kartu=" + urlEncode(uid) + "&request_id=" + urlEncode(id);
  if (offline) body += "&offline=1&tapped_at=" + urlEncode(isoTime(tappedAt));
  int status = http.POST(body);
  response = http.getString();
  http.end();
  return status;
}

void showServerResponse(int status, const String& response, const String& uid) {
  String action = jsonValue(response, "action");
  String name = jsonValue(response, "pegawai");
  String minutes = jsonValue(response, "remaining_minutes");
  if (name != "") cacheName(uid, name);

  if (action == "check_in") {
    displayMessage(name, "Berhasil Masuk");
    ledResult(true);
  } else if (action == "check_out") {
    displayMessage(name, "Berhasil Pulang");
    ledResult(true);
  } else if (action == "wait_6_hours") {
    displayMessage(name, "Tunggu " + minutes + " mnt");
    ledResult(false);
  } else if (action == "duplicate_tap") {
    displayMessage(name, "Tap Sudah Dibaca");
    ledResult(true);
  } else if (action == "register_card") {
    displayMessage(name, "Kartu Terdaftar");
    ledResult(true);
  } else if (action == "already_registered") {
    displayMessage(name, "Kartu Digunakan");
    ledResult(false);
  } else if (action == "uid_not_found" || status == 404) {
    displayMessage("KARTU TDK ADA", "Hubungi Admin");
    ledResult(false);
  } else if (status == 401) {
    displayMessage("ALAT DITOLAK", "Cek Kode/Kunci");
    ledResult(false);
  } else {
    displayMessage("SERVER GAGAL", "HTTP " + String(status));
    ledResult(false);
  }
}

void synchronizeQueue() {
  if (WiFi.status() != WL_CONNECTED || !LittleFS.begin() || !LittleFS.exists(QUEUE_FILE)) return;
  File input = LittleFS.open(QUEUE_FILE, "r");
  File output = LittleFS.open("/attendance.tmp", "w");
  bool keepRemaining = false;
  while (input && input.available()) {
    String line = input.readStringUntil('\n');
    line.trim();
    int first = line.indexOf('|');
    int second = line.indexOf('|', first + 1);
    if (first < 0 || second < 0) continue;
    String id = line.substring(0, first);
    String uid = line.substring(first + 1, second);
    time_t tappedAt = (time_t) line.substring(second + 1).toInt();
    if (!keepRemaining) {
      String response;
      int status = sendTap(uid, id, tappedAt, true, response);
      if (status == 200 || status == 404) continue;
      keepRemaining = true;
    }
    output.println(line);
  }
  if (input) input.close();
  if (output) output.close();
  LittleFS.remove(QUEUE_FILE);
  LittleFS.rename("/attendance.tmp", QUEUE_FILE);
}

void connectWiFi() {
  WiFi.mode(WIFI_STA);
  WiFiManager manager;
  manager.setConfigPortalTimeout(180);
  displayMessage("SAMBUNG WIFI", "Mohon Tunggu");
  if (!manager.autoConnect(WIFI_AP_NAME, WIFI_AP_PASSWORD)) {
    displayMessage("WIFI GAGAL", "Restart Alat");
    delay(2000);
    ESP.restart();
  }
  configTime(0, 0, "pool.ntp.org", "time.google.com");
  displayMessage("WIFI TERHUBUNG", WiFi.localIP().toString());
  delay(1200);
}

void handleCard(const String& uid) {
  if (uid == lastUid && millis() - lastUidAt < 3000) return;
  lastUid = uid;
  lastUidAt = millis();
  displayMessage("MEMBACA KARTU", uid);

  String id = requestId(uid);
  time_t tappedAt = time(nullptr);
  if (WiFi.status() != WL_CONNECTED) {
    queueTap(uid, id, tappedAt);
    String name = cachedName(uid);
    displayMessage(name == "" ? uid : name, "Disimpan Offline");
    ledResult(true);
    return;
  }

  String response;
  int status = sendTap(uid, id, tappedAt, false, response);
  if (status <= 0) {
    queueTap(uid, id, tappedAt);
    String name = cachedName(uid);
    displayMessage(name == "" ? uid : name, "Disimpan Offline");
    ledResult(true);
    return;
  }
  showServerResponse(status, response, uid);
}

void setup() {
  Serial.begin(9600);
  pinMode(LED_HIJAU, OUTPUT);
  pinMode(LED_MERAH, OUTPUT);
  digitalWrite(LED_HIJAU, HIGH);
  digitalWrite(LED_MERAH, HIGH);
  Wire.begin(I2C_SDA_PIN, I2C_SCL_PIN);
  lcd.begin(16, 2);
  lcd.backlight();
  displayMessage("PRESENSI SPPG", "Memulai...");
  LittleFS.begin();
  SPI.begin();
  rfid.PCD_Init();
  connectWiFi();
  synchronizeQueue();
  showIdle();
}

void loop() {
  if (WiFi.status() != WL_CONNECTED) {
    if (wifiDisconnectedSince == 0) wifiDisconnectedSince = millis();
    WiFi.reconnect();
  } else {
    if (wifiDisconnectedSince != 0) synchronizeQueue();
    wifiDisconnectedSince = 0;
  }

  if (rfid.PICC_IsNewCardPresent() && rfid.PICC_ReadCardSerial()) {
    String uid = "";
    for (byte i = 0; i < rfid.uid.size; i++) {
      if (rfid.uid.uidByte[i] < 0x10) uid += "0";
      uid += String(rfid.uid.uidByte[i], HEX);
    }
    uid.toUpperCase();
    handleCard(uid);
    rfid.PICC_HaltA();
    rfid.PCD_StopCrypto1();
    delay(1000);
    showIdle();
  }
  delay(100);
}
