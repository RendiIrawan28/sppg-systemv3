package id.sppg.mobile.ui

import id.sppg.mobile.data.remote.OperationalRecord

/** Cleaning history is a dated work log, not only a list of completed work. */
internal fun OperationalRecord.visibleInWorkHistory(module: String, showHistory: Boolean): Boolean =
    if (module == "kebersihan" && showHistory) true else isHistory == showHistory
