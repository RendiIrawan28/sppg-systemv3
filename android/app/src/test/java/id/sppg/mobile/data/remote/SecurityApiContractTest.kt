package id.sppg.mobile.data.remote

import com.google.gson.Gson
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Test
import id.sppg.mobile.ui.isImageUrl
import id.sppg.mobile.ui.isTechnicalRecordNumber
import id.sppg.mobile.ui.resolveAppMediaUrl

class SecurityApiContractTest {
    private val gson = Gson()

    @Test
    fun expiredShiftAndReportPhotoAreParsedFromServerContract() {
        val response = gson.fromJson(
            """
            {
              "data": {
                "active_shift": null,
                "recent_shifts": [{
                  "id": 7,
                  "started_at": "2026-08-01T08:00:00+07:00",
                  "completed_at": "2026-08-01T20:00:00+07:00",
                  "status": "expired",
                  "reports_count": 3,
                  "reports_expected": 4
                }],
                "pending_tasks": [],
                "can_start_shift": true
              }
            }
            """.trimIndent(),
            SecurityOverviewResponse::class.java,
        )

        assertNull(response.data.activeShift)
        assertEquals("expired", response.data.recentShifts.single().status)
        assertEquals(3, response.data.recentShifts.single().reportsCount)
        assertFalse(response.data.pendingTasks.any())
    }

    @Test
    fun periodicReportKeepsDocumentationUrlAndChecklistValues() {
        val report = gson.fromJson(
            """
            {
              "id": 10,
              "sequence_number": 2,
              "due_at": "2026-08-01T14:00:00+07:00",
              "reported_at": "2026-08-01T14:05:00+07:00",
              "situation": "attention",
              "gate_secure": true,
              "perimeter_secure": false,
              "access_activity": "Kendaraan supplier masuk",
              "visitor_activity": null,
              "notes": "Pemeriksaan selesai",
              "photo_url": "http://sppg.test/storage/security/report-2.jpg"
            }
            """.trimIndent(),
            SecurityReportItem::class.java,
        )

        assertEquals(2, report.sequenceNumber)
        assertEquals("attention", report.situation)
        assertEquals(true, report.gateSecure)
        assertEquals(false, report.perimeterSecure)
        assertEquals("http://sppg.test/storage/security/report-2.jpg", report.photoUrl)
    }

    @Test
    fun localServerPhotoUrlUsesTheApiServerAddressInsideAndroid() {
        val resolved = resolveAppMediaUrl(
            rawUrl = "http://127.0.0.1:8000/storage/v3/security/reports/photo.jpg",
            apiBaseUrl = "http://192.168.18.14:8000/api/mobile/",
        )

        assertEquals(
            "http://192.168.18.14:8000/storage/v3/security/reports/photo.jpg",
            resolved,
        )
        assertEquals(true, isImageUrl(resolved))
    }

    @Test
    fun technicalUuidIsHiddenFromOperationalCards() {
        assertEquals(true, isTechnicalRecordNumber("1628f15e-3ebe-44a3-b6a8-4791c0e4d848"))
        assertEquals(false, isTechnicalRecordNumber("INS/2026/0001"))
    }
}
