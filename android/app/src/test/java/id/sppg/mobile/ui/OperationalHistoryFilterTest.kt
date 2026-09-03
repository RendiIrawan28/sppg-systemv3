package id.sppg.mobile.ui

import id.sppg.mobile.data.remote.OperationalRecord
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class OperationalHistoryFilterTest {
    private fun record(history: Boolean) = OperationalRecord(
        id = 1, number = "CLEAN-TEST", date = "2026-09-02", title = "Area uji",
        subtitle = null, state = if (history) "ready" else "planned", stateLabel = null,
        status = "draft", statusLabel = null, isHistory = history, assignee = null,
        metrics = emptyList(), fields = null, sections = null, formFields = null, capabilities = null,
    )

    @Test fun cleaningHistoryIncludesUnfinishedAndCompletedWork() {
        assertTrue(record(false).visibleInWorkHistory("kebersihan", true))
        assertTrue(record(true).visibleInWorkHistory("kebersihan", true))
    }

    @Test fun activeCleaningStillExcludesCompletedWork() {
        assertTrue(record(false).visibleInWorkHistory("kebersihan", false))
        assertFalse(record(true).visibleInWorkHistory("kebersihan", false))
    }

    @Test fun otherModulesKeepTheirExistingHistoryRules() {
        assertFalse(record(false).visibleInWorkHistory("pencucian", true))
        assertTrue(record(true).visibleInWorkHistory("pencucian", true))
    }
}
