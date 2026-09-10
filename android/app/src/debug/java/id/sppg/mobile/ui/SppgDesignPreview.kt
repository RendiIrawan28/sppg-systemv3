package id.sppg.mobile.ui

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.tooling.preview.Preview
import androidx.compose.ui.unit.dp
import id.sppg.mobile.ui.theme.SppgTheme

// Design review only: not a route, not connected to operational data or included in release.
@Preview(name = "320 dp • terang", widthDp = 320, heightDp = 800, showBackground = true)
@Preview(name = "360 dp • terang", widthDp = 360, heightDp = 800, showBackground = true)
@Preview(name = "390 dp • terang", widthDp = 390, heightDp = 844, showBackground = true)
@Preview(name = "412 dp • terang", widthDp = 412, heightDp = 915, showBackground = true)
@Composable
private fun LightDesignPreview() = DesignPreview(false)

@Preview(name = "320 dp • gelap", widthDp = 320, heightDp = 800, showBackground = true)
@Preview(name = "360 dp • gelap", widthDp = 360, heightDp = 800, showBackground = true)
@Preview(name = "390 dp • gelap", widthDp = 390, heightDp = 844, showBackground = true)
@Preview(name = "412 dp • gelap", widthDp = 412, heightDp = 915, showBackground = true)
@Composable
private fun DarkDesignPreview() = DesignPreview(true)

@Composable
private fun DesignPreview(dark: Boolean) {
    SppgTheme(darkTheme = dark) {
        Surface(color = MaterialTheme.colorScheme.background) {
            Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp),
                verticalArrangement = Arrangement.spacedBy(12.dp)) {
                Text("Pratinjau komponen", style = MaterialTheme.typography.titleLarge)
                WorkHistoryTabs(false, onShowHistoryChange = {})
                HistoryDateSelector("10-09-2026", {})
                SppgCard {
                    Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                        ModuleIcon("gudang")
                        Text("Penerimaan bahan", style = MaterialTheme.typography.titleMedium)
                        SppgStatusPill("Menunggu verifikasi gudang")
                        SppgTextField("", {}, label = { Text("Nama barang") }, modifier = Modifier.fillMaxWidth())
                        SppgTextField("", {}, label = { Text("Catatan penerimaan") }, minLines = 2, modifier = Modifier.fillMaxWidth())
                        SppgPrimaryButton("Simpan dokumentasi penerimaan", {}, Modifier.fillMaxWidth())
                        SppgOutlinedButton({}, Modifier.fillMaxWidth()) { Text("Lihat dokumentasi") }
                    }
                }
                SppgSectionCard {
                    Column {
                        SppgTimelineItem("Rute dipilih", true, false, false)
                        SppgTimelineItem("Dalam perjalanan", false, true, false)
                        SppgTimelineItem("Kembali ke SPPG", false, false, true)
                    }
                }
                SppgEmptyState("Belum ada riwayat", "Pilih tanggal untuk melihat pekerjaan yang tersimpan.")
            }
        }
    }
}
