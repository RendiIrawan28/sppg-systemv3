@file:OptIn(androidx.compose.foundation.layout.ExperimentalLayoutApi::class)

package id.sppg.mobile.ui

import android.content.ClipData
import android.content.Context
import android.content.Intent
import android.widget.Toast
import androidx.activity.compose.BackHandler
import androidx.compose.foundation.background
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.tween
import androidx.compose.foundation.clickable
import androidx.compose.foundation.Image
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.WindowInsets
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.safeDrawing
import androidx.compose.foundation.layout.statusBars
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.windowInsetsPadding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.Logout
import androidx.compose.material.icons.automirrored.outlined.ArrowForward
import androidx.compose.material.icons.outlined.AccountCircle
import androidx.compose.material.icons.outlined.Assessment
import androidx.compose.material.icons.outlined.Badge
import androidx.compose.material.icons.outlined.Campaign
import androidx.compose.material.icons.outlined.CalendarMonth
import androidx.compose.material.icons.outlined.GridView
import androidx.compose.material.icons.outlined.Groups
import androidx.compose.material.icons.outlined.Info
import androidx.compose.material.icons.outlined.Person
import androidx.compose.material.icons.outlined.School
import androidx.compose.material.icons.outlined.Dashboard
import androidx.compose.material.icons.outlined.ExpandLess
import androidx.compose.material.icons.outlined.ExpandMore
import androidx.compose.material.icons.outlined.Home
import androidx.compose.material.icons.outlined.Notifications
import androidx.compose.material.icons.outlined.Restaurant
import androidx.compose.material.icons.outlined.Visibility
import androidx.compose.material.icons.outlined.VisibilityOff
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.NavigationBarItemDefaults
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.luminance
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.platform.LocalFocusManager
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.input.VisualTransformation
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.content.FileProvider
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import id.sppg.mobile.core.notification.NotificationNavigationStore
import id.sppg.mobile.core.notification.NotificationRefreshBus
import id.sppg.mobile.R
import id.sppg.mobile.data.session.UserSession
import id.sppg.mobile.data.remote.MobileDailySummary
import id.sppg.mobile.data.remote.OperationalModule
import id.sppg.mobile.ui.theme.SppgTheme
import id.sppg.mobile.ui.theme.Leaf
import kotlinx.coroutines.flow.collect
import kotlinx.coroutines.delay
import java.io.File
import java.time.LocalDate
import java.time.format.DateTimeFormatter
import java.util.Locale

private sealed interface AppScreen {
    data object Dashboard : AppScreen
    data object Tasks : AppScreen
    data object Security : AppScreen
    data object FieldPlans : AppScreen
    data object FieldPlanCreate : AppScreen
    data class FieldPlanDetail(val id: Long) : AppScreen
    data class FieldPlanEdit(val id: Long) : AppScreen
    data class OperationalRecords(val slug: String, val label: String) : AppScreen
    data class OperationalDetail(val slug: String, val label: String, val id: Long) : AppScreen
    data class OperationalEdit(val slug: String, val label: String, val id: Long) : AppScreen
    data class OperationalCreate(val slug: String, val label: String) : AppScreen
    data class OperationalRelationEdit(
        val slug: String,
        val label: String,
        val recordId: Long,
        val sectionKey: String,
        val sectionTitle: String,
        val itemId: Long?,
    ) : AppScreen
}

private fun notificationTarget(
    targetScreen: String?,
    payload: Map<String, String>?,
): AppScreen = when (targetScreen) {
    "security" -> AppScreen.Security
    "field-plans" -> payload?.get("field_plan_id")?.toLongOrNull()
        ?.let { AppScreen.FieldPlanDetail(it) } ?: AppScreen.FieldPlans
    "operational" -> {
        val slug = payload?.get("module_slug")?.takeIf { it.isNotBlank() }
        val label = payload?.get("module_label")?.takeIf { it.isNotBlank() } ?: slug
        val recordId = payload?.get("record_id")?.toLongOrNull()
        if (slug != null && label != null && recordId != null) {
            AppScreen.OperationalDetail(slug, label, recordId)
        } else if (slug != null && label != null) {
            AppScreen.OperationalRecords(slug, label)
        } else {
            AppScreen.Tasks
        }
    }
    "tasks", "notifications" -> AppScreen.Tasks
    else -> AppScreen.Dashboard
}

@Composable
fun SppgApp(
    authViewModel: AuthViewModel,
    fieldPlanViewModel: FieldPlanViewModel,
    operationalViewModel: OperationalViewModel,
    notificationViewModel: NotificationViewModel,
    securityViewModel: SecurityViewModel,
) {
    val state by authViewModel.uiState.collectAsStateWithLifecycle()
    var splashMinimumElapsed by remember { mutableStateOf(false) }
    var logoutRequested by remember { mutableStateOf(false) }

    // Feedback logout harus muncul sejak tombol ditekan, termasuk selama
    // proses unregister token notifikasi yang terjadi sebelum AuthViewModel.logout().
    LaunchedEffect(state.session?.token) {
        if (state.session != null) {
            logoutRequested = false
        }
    }

    // Pertahankan splash minimal 1,8 detik agar branding SPPG/BGN terlihat
    // dengan baik. Jika proses pemuatan sesi lebih lama, splash otomatis
    // tetap tampil sampai proses tersebut selesai.
    LaunchedEffect(Unit) {
        delay(2_000L)
        splashMinimumElapsed = true
    }

    SppgTheme {
        when {
            !splashMinimumElapsed || state.isLoading -> LoadingScreen()
            state.session == null -> LoginScreen(
                isSubmitting = state.isSubmitting,
                errorMessage = state.errorMessage,
                onLogin = authViewModel::login,
                onDismissError = authViewModel::dismissError,
            )
            else -> AuthenticatedContent(
                session = requireNotNull(state.session),
                isLoggingOut = state.isSubmitting || logoutRequested,
                noticeMessage = state.noticeMessage,
                onDismissNotice = authViewModel::dismissNotice,
                onLogout = {
                    if (!logoutRequested) {
                        logoutRequested = true
                        notificationViewModel.unregisterDevice(authViewModel::logout)
                    }
                },
                fieldPlanViewModel = fieldPlanViewModel,
                operationalViewModel = operationalViewModel,
                notificationViewModel = notificationViewModel,
                securityViewModel = securityViewModel,
            )
        }
    }
}

@Composable
private fun AuthenticatedContent(
    session: UserSession,
    isLoggingOut: Boolean,
    noticeMessage: String?,
    onDismissNotice: () -> Unit,
    onLogout: () -> Unit,
    fieldPlanViewModel: FieldPlanViewModel,
    operationalViewModel: OperationalViewModel,
    notificationViewModel: NotificationViewModel,
    securityViewModel: SecurityViewModel,
) {
    var screen: AppScreen by remember(session.token) { mutableStateOf(AppScreen.Dashboard) }
    val fieldPlanState by fieldPlanViewModel.uiState.collectAsStateWithLifecycle()
    val operationalState by operationalViewModel.uiState.collectAsStateWithLifecycle()
    val notificationState by notificationViewModel.uiState.collectAsStateWithLifecycle()
    val securityState by securityViewModel.uiState.collectAsStateWithLifecycle()
    val context = LocalContext.current
    val watermarkProfile = remember(session.userName, session.roleLabel) {
        PhotoWatermarkProfile(
            name = session.userName,
            division = session.roleLabel,
        )
    }

    LaunchedEffect(session.token) {
        fieldPlanViewModel.resetSession()
        operationalViewModel.resetSession()
        notificationViewModel.resetSession()
        securityViewModel.resetSession()
        operationalViewModel.loadModules(force = true)
        notificationViewModel.registerDevice()
        notificationViewModel.load(force = true)
    }

    LaunchedEffect(session.token) {
        NotificationRefreshBus.events.collect {
            notificationViewModel.load(force = true)
        }
    }

    val notificationNavigation by NotificationNavigationStore.event.collectAsStateWithLifecycle()
    LaunchedEffect(notificationNavigation, session.token) {
        notificationNavigation?.let { event ->
            screen = notificationTarget(
                event.screen,
                mapOf(
                    "module_slug" to event.moduleSlug.orEmpty(),
                    "module_label" to event.moduleLabel.orEmpty(),
                    "record_id" to (event.recordId?.toString() ?: ""),
                    "field_plan_id" to (event.fieldPlanId?.toString() ?: ""),
                ),
            )
            notificationViewModel.load(force = true)
            NotificationNavigationStore.consume()
        }
    }

    NotificationPermissionEffect()

    fun navigateBack() {
        screen = when (val current = screen) {
            AppScreen.Dashboard -> AppScreen.Dashboard
            AppScreen.Tasks -> AppScreen.Dashboard
            AppScreen.Security -> AppScreen.Dashboard
            AppScreen.FieldPlans -> AppScreen.Dashboard
            AppScreen.FieldPlanCreate -> AppScreen.FieldPlans
            is AppScreen.FieldPlanDetail -> {
                fieldPlanViewModel.clearDetail()
                AppScreen.FieldPlans
            }
            is AppScreen.FieldPlanEdit -> AppScreen.FieldPlanDetail(current.id)
            is AppScreen.OperationalRecords -> AppScreen.Dashboard
            is AppScreen.OperationalDetail -> {
                operationalViewModel.clearDetail()
                AppScreen.OperationalRecords(current.slug, current.label)
            }
            is AppScreen.OperationalEdit -> AppScreen.OperationalDetail(current.slug, current.label, current.id)
            is AppScreen.OperationalCreate -> AppScreen.OperationalRecords(current.slug, current.label)
            is AppScreen.OperationalRelationEdit ->
                AppScreen.OperationalDetail(current.slug, current.label, current.recordId)
        }
    }

    BackHandler(enabled = screen != AppScreen.Dashboard) { navigateBack() }

    Box(
        modifier = Modifier
            .fillMaxSize()
            .background(MaterialTheme.colorScheme.surface)
            .windowInsetsPadding(WindowInsets.statusBars),
    ) {
        when (val current = screen) {
        AppScreen.Dashboard -> DashboardScreen(
            session = session,
            isLoggingOut = isLoggingOut,
            operationalState = operationalState,
            noticeMessage = noticeMessage,
            onDismissNotice = onDismissNotice,
            onLogout = onLogout,
            unreadNotificationCount = notificationState.unreadCount,
            onOpenTasks = { screen = AppScreen.Tasks },
            onOpenFieldPlans = { screen = AppScreen.FieldPlans },
            onLoadOperationalModules = operationalViewModel::loadModules,
            onOpenOperational = { slug, label ->
                screen = if (slug == "keamanan") {
                    AppScreen.Security
                } else {
                    AppScreen.OperationalRecords(slug, label)
                }
            },
        )
        AppScreen.Tasks -> TaskListScreen(
            state = notificationState,
            onBack = { screen = AppScreen.Dashboard },
            onRefresh = { notificationViewModel.load(force = true) },
            onLoad = { notificationViewModel.load() },
            onTaskClick = { task ->
                screen = if (task.screen == "security") AppScreen.Security else AppScreen.Tasks
            },
            onNotificationClick = { notification ->
                notificationViewModel.markRead(notification) { selected ->
                    screen = notificationTarget(selected.screen, selected.payload)
                }
            },
            onMarkAllRead = notificationViewModel::markAllRead,
        )
        AppScreen.Security -> SecurityScreen(
            state = securityState,
            watermarkProfile = watermarkProfile,
            onBack = { screen = AppScreen.Dashboard },
            onLoad = { securityViewModel.load() },
            onRefresh = { securityViewModel.load(force = true) },
            onDateChange = securityViewModel::filterHistory,
            onStartShift = securityViewModel::startShift,
            onSubmitReport = securityViewModel::submitReport,
            onClearFeedback = securityViewModel::clearFeedback,
        )
        AppScreen.FieldPlans -> FieldPlanListScreen(
            state = fieldPlanState,
            onBack = { screen = AppScreen.Dashboard },
            onRefresh = { fieldPlanViewModel.loadPlans(force = true) },
            onLoad = fieldPlanViewModel::loadPlans,
            onLoadMore = fieldPlanViewModel::loadMorePlans,
            onShowActive = fieldPlanViewModel::showActivePlans,
            onHistoryDateChange = fieldPlanViewModel::filterHistory,
            onPlanClick = { screen = AppScreen.FieldPlanDetail(it) },
            onCreate = {
                fieldPlanViewModel.clearFeedback()
                fieldPlanViewModel.loadOptions(force = true)
                screen = AppScreen.FieldPlanCreate
            },
        )
        AppScreen.FieldPlanCreate -> FieldPlanCreateScreen(
            state = fieldPlanState,
            onBack = { screen = AppScreen.FieldPlans },
            onLoadOptions = fieldPlanViewModel::loadOptions,
            onCreate = { distributionDate, legacyOptionId, notes ->
                fieldPlanViewModel.createPlan(distributionDate, legacyOptionId, notes) { id ->
                    fieldPlanViewModel.clearFeedback()
                    screen = AppScreen.FieldPlanEdit(id)
                }
            },
            onClearFeedback = fieldPlanViewModel::clearFeedback,
        )
        is AppScreen.FieldPlanDetail -> FieldPlanDetailScreen(
            state = fieldPlanState,
            planId = current.id,
            onBack = {
                fieldPlanViewModel.clearDetail()
                screen = AppScreen.FieldPlans
            },
            onLoad = fieldPlanViewModel::loadPlan,
            onEdit = {
                fieldPlanViewModel.clearFeedback()
                screen = AppScreen.FieldPlanEdit(current.id)
            },
            onCheckReadiness = fieldPlanViewModel::checkReadiness,
            onActivate = fieldPlanViewModel::activatePlan,
            onRefreshBeneficiaries = fieldPlanViewModel::refreshBeneficiaries,
            onDelete = {
                fieldPlanViewModel.deletePlan { screen = AppScreen.FieldPlans }
            },
            onOpenDocument = { format ->
                fieldPlanViewModel.downloadDocument(current.id, format) { file ->
                    runCatching {
                        val uri = FileProvider.getUriForFile(
                            context,
                            "${context.packageName}.fileprovider",
                            file,
                        )
                        val mimeType = if (format == "xlsx") {
                            "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                        } else {
                            "application/pdf"
                        }
                        val intent = Intent(Intent.ACTION_VIEW).apply {
                            setDataAndType(uri, mimeType)
                            addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
                        }
                        context.startActivity(Intent.createChooser(intent, "Buka dokumen rencana"))
                    }.onFailure {
                        Toast.makeText(
                            context,
                            "Tidak ada aplikasi yang dapat membuka dokumen ini.",
                            Toast.LENGTH_LONG,
                        ).show()
                    }
                }
            },
            onShareDocument = { format ->
                fieldPlanViewModel.downloadDocument(current.id, format) { file ->
                    shareDocument(
                        context = context,
                        file = file,
                        mimeType = if (format == "xlsx") {
                            "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                        } else {
                            "application/pdf"
                        },
                        chooserTitle = "Bagikan dokumen rencana",
                    )
                }
            },
            onClearFeedback = fieldPlanViewModel::clearFeedback,
        )
        is AppScreen.FieldPlanEdit -> FieldPlanEditScreen(
            state = fieldPlanState,
            planId = current.id,
            onLoad = fieldPlanViewModel::loadPlan,
            onBack = {
                fieldPlanViewModel.clearFeedback()
                screen = AppScreen.FieldPlanDetail(current.id)
            },
            onSave = fieldPlanViewModel::updatePlan,
            onSaved = {
                fieldPlanViewModel.clearFeedback()
                screen = AppScreen.FieldPlanDetail(current.id)
            },
        )
        is AppScreen.OperationalRecords -> OperationalRecordListScreen(
            state = operationalState,
            module = current.slug,
            moduleLabel = current.label,
            onBack = { screen = AppScreen.Dashboard },
            onLoad = {
                operationalViewModel.loadRecords(
                    it,
                    force = true,
                    date = if (it.startsWith("gudang-stok") || it in setOf("pengolahan", "kebersihan")) null else LocalDate.now().toString(),
                )
            },
            onRefresh = operationalViewModel::refreshRecords,
            onFilterChange = operationalViewModel::filterRecords,
            onSearchChange = operationalViewModel::searchRecords,
            onLoadMore = operationalViewModel::loadMoreRecords,
            onRecordClick = {
                screen = AppScreen.OperationalDetail(current.slug, current.label, it)
            },
            onCreate = {
                if (current.slug == "keamanan") {
                    operationalViewModel.prepareCreate(current.slug)
                    operationalViewModel.createRecord(current.slug) { id ->
                        screen = AppScreen.OperationalDetail(current.slug, current.label, id)
                    }
                } else {
                    operationalViewModel.prepareCreateFresh(current.slug) {
                        screen = AppScreen.OperationalCreate(current.slug, current.label)
                    }
                }
            },
        )
        is AppScreen.OperationalDetail -> OperationalRecordDetailScreen(
            state = operationalState,
            module = current.slug,
            moduleLabel = current.label,
            recordId = current.id,
            onBack = {
                operationalViewModel.clearDetail()
                screen = AppScreen.OperationalRecords(current.slug, current.label)
            },
            onLoad = operationalViewModel::loadRecord,
            onEdit = {
                operationalViewModel.prepareEdit()
                screen = AppScreen.OperationalEdit(current.slug, current.label, current.id)
            },
            onDelete = {
                operationalViewModel.deleteRecord(current.slug, current.id) {
                    screen = AppScreen.OperationalRecords(current.slug, current.label)
                }
            },
            watermarkProfile = watermarkProfile,
            onAction = { action, notes, fields, files ->
                operationalViewModel.runAction(
                    current.slug,
                    current.id,
                    action,
                    notes,
                    fields,
                    files,
                )
            },
            onOpenDocument = { documentType ->
                operationalViewModel.downloadDocument(current.slug, current.id, documentType) { file ->
                    runCatching {
                        val uri = FileProvider.getUriForFile(
                            context,
                            "${context.packageName}.fileprovider",
                            file,
                        )
                        val intent = Intent(Intent.ACTION_VIEW).apply {
                            setDataAndType(uri, "application/pdf")
                            addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
                        }
                        context.startActivity(Intent.createChooser(intent, "Buka dokumen PDF"))
                    }.onFailure {
                        Toast.makeText(
                            context,
                            "Tidak ada aplikasi pembaca PDF pada perangkat ini.",
                            Toast.LENGTH_LONG,
                        ).show()
                    }
                }
            },
            onShareDocument = { documentType ->
                operationalViewModel.downloadDocument(current.slug, current.id, documentType) { file ->
                    shareDocument(
                        context = context,
                        file = file,
                        mimeType = "application/pdf",
                        chooserTitle = "Bagikan laporan ${current.label}",
                    )
                }
            },
            onOpenCleaningWaste = {
                screen = AppScreen.OperationalRecords(
                    "ba-limbah-kebersihan",
                    "Berita Acara Limbah Kebersihan",
                )
            },
            onRelationCreate = { section ->
                operationalViewModel.prepareRelationCreate(section.key)
                screen = AppScreen.OperationalRelationEdit(
                    current.slug, current.label, current.id, section.key, section.title, null,
                )
            },
            onRelationEdit = { section, item ->
                operationalViewModel.prepareRelationEdit(section.key, item.id)
                screen = AppScreen.OperationalRelationEdit(
                    current.slug, current.label, current.id, section.key, section.title, item.id,
                )
            },
            onRelationDelete = { section, item ->
                operationalViewModel.deleteRelation(current.slug, current.id, section.key, item.id)
            },
            onRelationAction = { section, item, action, notes, fields, files ->
                operationalViewModel.runRelationAction(
                    current.slug, current.id, section.key, item.id, action, notes, fields, files,
                )
            },
        )
        is AppScreen.OperationalEdit -> OperationalRecordEditScreen(
            state = operationalState,
            moduleLabel = current.label,
            isCreate = false,
            watermarkProfile = watermarkProfile,
            onBack = {
                operationalViewModel.clearFeedback()
                screen = AppScreen.OperationalDetail(current.slug, current.label, current.id)
            },
            onPrepare = operationalViewModel::prepareEdit,
            onValueChange = operationalViewModel::updateEditValue,
            onFileSelected = operationalViewModel::updateEditFile,
            fileValues = operationalState.editFiles,
            onSave = {
                operationalViewModel.saveRecord(current.slug, current.id) {
                    screen = AppScreen.OperationalDetail(current.slug, current.label, current.id)
                }
            },
        )
        is AppScreen.OperationalCreate -> OperationalRecordEditScreen(
            state = operationalState,
            moduleLabel = current.label,
            isCreate = true,
            createActionLabel = when (current.slug) {
                "pengolahan" -> "Mulai produksi"
                "pemorsian" -> "Mulai Pemorsian"
                else -> null
            },
            watermarkProfile = watermarkProfile,
            onBack = {
                operationalViewModel.clearFeedback()
                screen = AppScreen.OperationalRecords(current.slug, current.label)
            },
            onPrepare = { operationalViewModel.prepareCreate(current.slug) },
            onValueChange = operationalViewModel::updateEditValue,
            onFileSelected = operationalViewModel::updateEditFile,
            fileValues = operationalState.editFiles,
            onSave = {
                operationalViewModel.createRecord(current.slug) { id ->
                    screen = AppScreen.OperationalDetail(current.slug, current.label, id)
                }
            },
        )
        is AppScreen.OperationalRelationEdit -> OperationalRecordEditScreen(
            state = operationalState,
            moduleLabel = current.sectionTitle,
            isCreate = current.itemId == null,
            watermarkProfile = watermarkProfile,
            onBack = {
                operationalViewModel.clearFeedback()
                screen = AppScreen.OperationalDetail(current.slug, current.label, current.recordId)
            },
            onPrepare = {
                if (current.itemId == null) {
                    operationalViewModel.prepareRelationCreate(current.sectionKey)
                } else {
                    operationalViewModel.prepareRelationEdit(current.sectionKey, current.itemId)
                }
            },
            onValueChange = operationalViewModel::updateEditValue,
            onFileSelected = operationalViewModel::updateEditFile,
            fileValues = operationalState.editFiles,
            onSave = {
                operationalViewModel.saveRelation(
                    current.slug,
                    current.recordId,
                    current.sectionKey,
                    current.itemId,
                ) {
                    screen = AppScreen.OperationalDetail(current.slug, current.label, current.recordId)
                }
            },
        )
    }

        LogoutLoadingOverlay(visible = isLoggingOut)
    }
}

@Composable
private fun LogoutLoadingOverlay(visible: Boolean) {
    val overlayAlpha by animateFloatAsState(
        targetValue = if (visible) 1f else 0f,
        animationSpec = tween(durationMillis = 180),
        label = "logoutOverlayAlpha",
    )
    val cardScale by animateFloatAsState(
        targetValue = if (visible) 1f else 0.92f,
        animationSpec = tween(durationMillis = 220),
        label = "logoutCardScale",
    )

    if (visible || overlayAlpha > 0.01f) {
        Box(
            modifier = Modifier
                .fillMaxSize()
                .graphicsLayer { alpha = overlayAlpha }
                .background(MaterialTheme.colorScheme.scrim.copy(alpha = 0.50f))
                .clickable(enabled = visible) {},
            contentAlignment = Alignment.Center,
        ) {
            SppgCard(
                modifier = Modifier
                    .padding(horizontal = 36.dp)
                    .graphicsLayer {
                        scaleX = cardScale
                        scaleY = cardScale
                    },
                shape = RoundedCornerShape(22.dp),
                elevation = CardDefaults.cardElevation(defaultElevation = 8.dp),
            ) {
                Column(
                    modifier = Modifier.padding(horizontal = 30.dp, vertical = 26.dp),
                    horizontalAlignment = Alignment.CenterHorizontally,
                    verticalArrangement = Arrangement.spacedBy(14.dp),
                ) {
                    CircularProgressIndicator(
                        modifier = Modifier.size(38.dp),
                        strokeWidth = 3.dp,
                        color = MaterialTheme.colorScheme.primary,
                    )
                    Text(
                        text = "Keluar dari akun…",
                        style = MaterialTheme.typography.titleMedium,
                        fontWeight = FontWeight.Bold,
                        textAlign = TextAlign.Center,
                    )
                    Text(
                        text = "Sedang menutup sesi Anda.",
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                        textAlign = TextAlign.Center,
                    )
                }
            }
        }
    }
}

@Composable
private fun LoadingScreen() {
    var logoStarted by remember { mutableStateOf(false) }
    var textStarted by remember { mutableStateOf(false) }

    LaunchedEffect(Unit) {
        logoStarted = true
        delay(320L)
        textStarted = true
    }

    val logoAlpha by animateFloatAsState(
        targetValue = if (logoStarted) 1f else 0f,
        animationSpec = tween(durationMillis = 550),
        label = "splashLogoAlpha",
    )
    val logoScale by animateFloatAsState(
        targetValue = if (logoStarted) 1f else 0.88f,
        animationSpec = tween(durationMillis = 650),
        label = "splashLogoScale",
    )
    val textAlpha by animateFloatAsState(
        targetValue = if (textStarted) 1f else 0f,
        animationSpec = tween(durationMillis = 500),
        label = "splashTextAlpha",
    )

    Box(
        modifier = Modifier
            .fillMaxSize()
            .background(MaterialTheme.colorScheme.background),
    ) {
        // Ornamen gelombang bawah mengikuti bahasa visual aplikasi.
        Box(
            modifier = Modifier
                .align(Alignment.BottomCenter)
                .fillMaxWidth()
                .height(185.dp)
                .background(
                    color = MaterialTheme.colorScheme.primary.copy(alpha = 0.08f),
                    shape = RoundedCornerShape(topStart = 86.dp, topEnd = 26.dp),
                ),
        )
        Box(
            modifier = Modifier
                .align(Alignment.BottomCenter)
                .fillMaxWidth()
                .height(112.dp)
                .background(
                    color = MaterialTheme.colorScheme.primary.copy(alpha = 0.12f),
                    shape = RoundedCornerShape(topStart = 30.dp, topEnd = 92.dp),
                ),
        )

        Column(
            modifier = Modifier
                .fillMaxSize()
                .windowInsetsPadding(WindowInsets.safeDrawing)
                .padding(horizontal = 28.dp, vertical = 28.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Spacer(Modifier.weight(1f))

            Image(
                painter = painterResource(R.drawable.logo_bgn),
                contentDescription = "Logo Badan Gizi Nasional",
                modifier = Modifier
                    .size(142.dp)
                    .graphicsLayer {
                        scaleX = logoScale
                        scaleY = logoScale
                    }
                    .alpha(logoAlpha),
                contentScale = ContentScale.Fit,
            )

            Spacer(Modifier.height(20.dp))

            // Warna splash mengikuti theme agar tetap kontras di Light dan Dark Mode.
            val splashNavy = MaterialTheme.colorScheme.onBackground
            val splashBlue = MaterialTheme.colorScheme.primary
            val splashMuted = MaterialTheme.colorScheme.onSurfaceVariant

            Column(
                modifier = Modifier.alpha(textAlpha),
                horizontalAlignment = Alignment.CenterHorizontally,
            ) {
                Text(
                    text = "SPPG",
                    fontSize = 42.sp,
                    lineHeight = 48.sp,
                    fontWeight = FontWeight.ExtraBold,
                    color = splashBlue,
                    letterSpacing = 0.5.sp,
                    textAlign = TextAlign.Center,
                )

                Spacer(Modifier.height(6.dp))

                Text(
                    text = "Satuan Pelayanan Pemenuhan Gizi",
                    fontSize = 19.sp,
                    lineHeight = 26.sp,
                    fontWeight = FontWeight.Bold,
                    color = splashNavy,
                    textAlign = TextAlign.Center,
                )

                Spacer(Modifier.height(10.dp))

                Box(
                    modifier = Modifier
                        .width(44.dp)
                        .height(3.dp)
                        .background(
                            color = splashBlue.copy(alpha = 0.85f),
                            shape = RoundedCornerShape(50),
                        ),
                )

                Spacer(Modifier.height(10.dp))

                Text(
                    text = "BADAN GIZI NASIONAL",
                    fontSize = 14.sp,
                    lineHeight = 20.sp,
                    fontWeight = FontWeight.SemiBold,
                    color = splashMuted,
                    letterSpacing = 1.1.sp,
                    textAlign = TextAlign.Center,
                )
            }

            Spacer(Modifier.height(28.dp))

            CircularProgressIndicator(
                modifier = Modifier
                    .size(26.dp)
                    .alpha(textAlpha),
                strokeWidth = 3.dp,
                color = MaterialTheme.colorScheme.primary,
            )

            Spacer(Modifier.weight(1f))

            Text(
                "Bersama untuk Generasi Sehat dan Cerdas",
                modifier = Modifier.alpha(textAlpha),
                style = MaterialTheme.typography.bodyMedium,
                fontWeight = FontWeight.Medium,
                color = MaterialTheme.colorScheme.primary,
            )
            Spacer(Modifier.height(30.dp))
        }
    }
}

@Composable
private fun LoginScreen(
    isSubmitting: Boolean,
    errorMessage: String?,
    onLogin: (String, String) -> Unit,
    onDismissError: () -> Unit,
) {
    var login by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    var passwordVisible by remember { mutableStateOf(false) }
    val focusManager = LocalFocusManager.current

    LazyColumn(
        modifier = Modifier
            .fillMaxSize()
            .background(MaterialTheme.colorScheme.background)
            .windowInsetsPadding(WindowInsets.safeDrawing),
        contentPadding = PaddingValues(horizontal = SppgPagePadding, vertical = 24.dp),
        verticalArrangement = Arrangement.Center,
    ) {
        item {
            Column {
                Column(Modifier.fillMaxWidth(), horizontalAlignment = Alignment.CenterHorizontally) {
                    Image(
                        painter = painterResource(R.drawable.logo_bgn),
                        contentDescription = "Logo Badan Gizi Nasional",
                        modifier = Modifier.size(132.dp),
                        contentScale = ContentScale.Fit,
                    )
                    Text(
                        "SPPG",
                        style = MaterialTheme.typography.headlineMedium,
                        fontWeight = FontWeight.Bold,
                        color = MaterialTheme.colorScheme.primary,
                    )
                    Text(
                        "Satuan Pelayanan Pemenuhan Gizi",
                        style = MaterialTheme.typography.bodyMedium,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }
                Spacer(Modifier.height(24.dp))
                SppgCard(
                    colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
                    shape = RoundedCornerShape(16.dp),
                    border = androidx.compose.foundation.BorderStroke(1.dp, MaterialTheme.colorScheme.outlineVariant),
                ) {
                    Column(Modifier.padding(20.dp)) {
                        Text("Selamat Datang", style = MaterialTheme.typography.titleLarge)
                        Spacer(Modifier.height(5.dp))
                        Text("Gunakan akun yang sama dengan website SPPG.", color = MaterialTheme.colorScheme.onSurfaceVariant)
                        Spacer(Modifier.height(20.dp))
                        SppgTextField(
                            value = login,
                            onValueChange = {
                                login = it
                                if (errorMessage != null) onDismissError()
                            },
                            modifier = Modifier.fillMaxWidth(),
                            label = { Text("Email atau nomor pegawai") },
                            shape = RoundedCornerShape(14.dp),
                            singleLine = true,
                            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email, imeAction = ImeAction.Next),
                        )
                        Spacer(Modifier.height(14.dp))
                        SppgTextField(
                            value = password,
                            onValueChange = {
                                password = it
                                if (errorMessage != null) onDismissError()
                            },
                            modifier = Modifier.fillMaxWidth(),
                            label = { Text("Kata sandi") },
                            shape = RoundedCornerShape(14.dp),
                            singleLine = true,
                            visualTransformation = if (passwordVisible) VisualTransformation.None else PasswordVisualTransformation(),
                            trailingIcon = {
                                IconButton(onClick = { passwordVisible = !passwordVisible }) {
                                    Icon(
                                        if (passwordVisible) Icons.Outlined.VisibilityOff else Icons.Outlined.Visibility,
                                        contentDescription = if (passwordVisible) "Sembunyikan kata sandi" else "Tampilkan kata sandi",
                                    )
                                }
                            },
                            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password, imeAction = ImeAction.Done),
                            keyboardActions = KeyboardActions(onDone = {
                                focusManager.clearFocus()
                                if (!isSubmitting) onLogin(login, password)
                            }),
                        )
                        if (errorMessage != null) {
                            Spacer(Modifier.height(12.dp))
                            Text(userFriendlyUiMessage(errorMessage), color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodyMedium)
                        }
                        Spacer(Modifier.height(20.dp))
                        SppgPrimaryButton(
                            label = if (isSubmitting) "Memproses…" else "Masuk",
                            onClick = {
                                focusManager.clearFocus()
                                onLogin(login, password)
                            },
                            modifier = Modifier.fillMaxWidth(),
                            enabled = !isSubmitting,
                        )
                    }
                }
            }
        }
    }
}

private data class FeatureItem(
    val title: String,
    val description: String,
    val status: String,
    val isAvailable: Boolean = false,
    val operationalSlug: String? = null,
    val operationalLabel: String? = null,
    val visualSlug: String = operationalSlug ?: "field-plans",
)

private data class FeatureGroup(
    val key: String,
    val title: String,
    val description: String,
    val visualSlug: String,
    val items: List<FeatureItem>,
)

private data class FeatureCluster(
    val key: String,
    val title: String,
    val items: List<FeatureItem>,
)

private enum class DashboardTab { Home, Modules, Beneficiaries, Reports, Account }

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun DashboardScreen(
    session: UserSession,
    isLoggingOut: Boolean,
    operationalState: OperationalUiState,
    noticeMessage: String?,
    onDismissNotice: () -> Unit,
    onLogout: () -> Unit,
    unreadNotificationCount: Int,
    onOpenTasks: () -> Unit,
    onOpenFieldPlans: () -> Unit,
    onLoadOperationalModules: (Boolean) -> Unit,
    onOpenOperational: (String, String) -> Unit,
) {
    var selectedTab by remember { mutableStateOf(DashboardTab.Home) }
    val isFieldAssistant = session.role == "asisten_lapangan"
    val features = buildList {
        if (isFieldAssistant) {
            add(
                FeatureItem(
                    title = "Rencana distribusi",
                    description = "Buat rencana, konfirmasi penerima, atur rute, dan aktivasi.",
                    status = "Siap digunakan",
                    isAvailable = true,
                    visualSlug = "field-plans",
                ),
            )
        }
        addAll(
            operationalState.modules.map { module ->
                FeatureItem(
                    title = module.label,
                    description = module.description,
                    status = "${module.recordCount} pekerjaan",
                    isAvailable = true,
                    operationalSlug = module.slug,
                    operationalLabel = module.label,
                )
            },
        )
    }

    val summary = operationalState.dailySummary
    val reportFeatures = features.filter { feature ->
        val slug = feature.operationalSlug.orEmpty().lowercase()
        val title = feature.title.lowercase()
        slug.contains("laporan") || slug.startsWith("ba-") ||
            slug.contains("rekap") || title.contains("laporan") || title.contains("berita acara")
    }
    val beneficiaryFeatures = features.filter { feature ->
        val slug = feature.operationalSlug.orEmpty().lowercase()
        val title = feature.title.lowercase()
        feature.visualSlug == "field-plans" || slug.contains("penerima") ||
            slug.contains("beneficiar") || title.contains("penerima") || title.contains("distribusi")
    }

    Scaffold(
        containerColor = MaterialTheme.colorScheme.background,
        topBar = {
            Row(
                modifier = Modifier
                    .fillMaxWidth()
                    .height(70.dp)
                    .background(MaterialTheme.colorScheme.surface)
                    .padding(horizontal = SppgPagePadding),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Image(
                    painter = painterResource(R.drawable.sppg_avatar_staff_male),
                    contentDescription = "Avatar pengguna",
                    modifier = Modifier
                        .size(34.dp)
                        .clip(CircleShape),
                    contentScale = ContentScale.Crop,
                )
                Spacer(Modifier.width(10.dp))
                Column(Modifier.weight(1f)) {
                    Text(
                        "Halo, ${session.userName}",
                        style = MaterialTheme.typography.titleMedium,
                        fontWeight = FontWeight.SemiBold,
                        maxLines = 1,
                    )
                    Text(
                        session.roleLabel,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                        style = MaterialTheme.typography.bodySmall,
                        maxLines = 1,
                    )
                }
                Box {
                    IconButton(onClick = onOpenTasks, modifier = Modifier.size(42.dp)) {
                        Icon(
                            Icons.Outlined.Notifications,
                            contentDescription = "Notifikasi",
                            modifier = Modifier.size(24.dp),
                            tint = MaterialTheme.colorScheme.primary,
                        )
                    }
                    if (unreadNotificationCount > 0) {
                        Box(
                            Modifier
                                .align(Alignment.TopEnd)
                                .padding(top = 5.dp, end = 5.dp)
                                .size(8.dp)
                                .background(MaterialTheme.colorScheme.secondary, CircleShape),
                        )
                    }
                }
            }
        },
        bottomBar = {
            NavigationBar(
                modifier = Modifier.navigationBarsPadding(),
                containerColor = MaterialTheme.colorScheme.surface,
                tonalElevation = 0.dp,
            ) {
                listOf(
                    Triple(DashboardTab.Home, "Beranda", Icons.Outlined.Home),
                    Triple(DashboardTab.Modules, "Menu", Icons.Outlined.GridView),
                    Triple(DashboardTab.Beneficiaries, "Penerima", Icons.Outlined.Groups),
                    Triple(DashboardTab.Reports, "Laporan", Icons.Outlined.Assessment),
                    Triple(DashboardTab.Account, "Profil", Icons.Outlined.Person),
                ).forEach { (tab, label, icon) ->
                    val selected = selectedTab == tab
                    NavigationBarItem(
                        selected = selected,
                        onClick = { selectedTab = tab },
                        icon = {
                            Box(
                                modifier = Modifier
                                    .size(width = 44.dp, height = 30.dp)
                                    .background(
                                        if (selected) MaterialTheme.colorScheme.primaryContainer else Color.Transparent,
                                        RoundedCornerShape(999.dp),
                                    ),
                                contentAlignment = Alignment.Center,
                            ) {
                                Icon(
                                    icon,
                                    contentDescription = null,
                                    modifier = Modifier.size(21.dp),
                                )
                            }
                        },
                        label = { Text(label, style = MaterialTheme.typography.labelSmall) },
                        colors = NavigationBarItemDefaults.colors(
                            selectedIconColor = MaterialTheme.colorScheme.primary,
                            selectedTextColor = MaterialTheme.colorScheme.primary,
                            unselectedIconColor = MaterialTheme.colorScheme.onSurfaceVariant,
                            unselectedTextColor = MaterialTheme.colorScheme.onSurfaceVariant,
                            indicatorColor = Color.Transparent,
                        ),
                    )
                }
            }
        },
    ) { innerPadding ->
        LazyColumn(
            modifier = Modifier.fillMaxSize().imePadding(),
            contentPadding = PaddingValues(
                start = SppgPagePadding,
                top = innerPadding.calculateTopPadding() + 12.dp,
                end = SppgPagePadding,
                bottom = innerPadding.calculateBottomPadding() + 48.dp,
            ),
            verticalArrangement = Arrangement.spacedBy(14.dp),
        ) {
            if (!noticeMessage.isNullOrBlank()) {
                item {
                    SppgCard(
                        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.secondaryContainer),
                        shape = RoundedCornerShape(16.dp),
                    ) {
                        Row(Modifier.padding(14.dp), verticalAlignment = Alignment.CenterVertically) {
                            Text(noticeMessage, modifier = Modifier.weight(1f), style = MaterialTheme.typography.bodySmall)
                            TextButton(onClick = onDismissNotice) { Text("Tutup") }
                        }
                    }
                }
            }

            if (operationalState.isLoading && operationalState.modules.isEmpty()) {
                item { SppgLoadingState("Menyiapkan ruang kerja…") }
            } else if (operationalState.errorMessage != null && operationalState.modules.isEmpty()) {
                item { SppgErrorState(operationalState.errorMessage, onRetry = { onLoadOperationalModules(true) }) }
            } else if (features.isEmpty() && selectedTab != DashboardTab.Account) {
                item { UnsupportedRoleCard() }
            } else {
                when (selectedTab) {
                    DashboardTab.Home -> {
                        item {
                            Image(
                                painter = painterResource(R.drawable.sppg_banner_hero),
                                contentDescription = "Makanan bergizi untuk masa depan generasi Indonesia",
                                modifier = Modifier
                                    .fillMaxWidth()
                                    .clip(RoundedCornerShape(18.dp)),
                                contentScale = ContentScale.FillWidth,
                            )
                        }
                        item {
                            DashboardMetricsGrid(summary = summary, modules = operationalState.modules)
                        }
                        item {
                            DashboardTodayMenuCard(summary = summary)
                        }
                        item {
                            DashboardSectionHeader(
                                title = "Menu Cepat",
                                subtitle = "Akses pekerjaan utama sesuai peran Anda.",
                            )
                        }
                        item {
                            val quick = features.take(4)
                            Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                                quick.chunked(4).forEach { row ->
                                    Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                                        row.forEach { feature ->
                                            val visual = moduleVisual(feature.visualSlug)
                                            SppgQuickAction(
                                                title = feature.title,
                                                icon = visual.icon,
                                                accent = visual.color,
                                                onClick = {
                                                    openDashboardFeature(feature, onOpenFieldPlans, onOpenOperational)
                                                },
                                                modifier = Modifier.weight(1f),
                                            )
                                        }
                                        repeat(4 - row.size) { Spacer(Modifier.weight(1f)) }
                                    }
                                }
                            }
                        }
                        item {
                            DashboardSectionHeader(
                                title = "Pengumuman",
                                subtitle = "Informasi operasional untuk hari ini.",
                            )
                        }
                        item {
                            SppgInfoBanner(
                                title = "Jaga kualitas dan ketertiban pencatatan",
                                message = "Pastikan setiap proses dicatat sesuai SOP dan data diperbarui setelah pekerjaan selesai.",
                                icon = Icons.Outlined.Campaign,
                            )
                        }
                        if (features.isNotEmpty()) {
                            item {
                                DashboardSectionHeader(
                                    title = "Pekerjaan Anda",
                                    subtitle = "Lanjutkan modul yang tersedia untuk akun ini.",
                                )
                            }
                            items(features.take(3), key = { "home-${it.operationalSlug ?: it.title}" }) { feature ->
                                DashboardFeatureCard(feature, onOpenFieldPlans, onOpenOperational)
                            }
                        }
                    }

                    DashboardTab.Modules -> {
                        item {
                            DashboardSectionHeader(
                                title = "Menu",
                                subtitle = "Pilih modul yang ingin Anda akses.",
                            )
                        }
                        items(features, key = { "module-${it.operationalSlug ?: it.title}" }) { feature ->
                            DashboardFeatureCard(feature, onOpenFieldPlans, onOpenOperational)
                        }
                    }

                    DashboardTab.Beneficiaries -> {
                        item {
                            DashboardSectionHeader(
                                title = "Penerima Manfaat",
                                subtitle = dashboardDateLabel(summary?.date)?.let { "Ringkasan layanan $it" }
                                    ?: "Ringkasan penerima berdasarkan data operasional hari ini.",
                            )
                        }
                        item {
                            Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                                SppgStatCard(
                                    label = "Penerima",
                                    value = (summary?.beneficiaries ?: 0).toString(),
                                    icon = Icons.Outlined.Groups,
                                    accent = Color(0xFF22B573),
                                    modifier = Modifier.weight(1f),
                                )
                                SppgStatCard(
                                    label = "Tujuan",
                                    value = (summary?.destinations ?: 0).toString(),
                                    icon = Icons.Outlined.School,
                                    accent = MaterialTheme.colorScheme.primary,
                                    modifier = Modifier.weight(1f),
                                )
                                SppgStatCard(
                                    label = "Porsi",
                                    value = (summary?.portions ?: 0).toString(),
                                    icon = Icons.Outlined.Restaurant,
                                    accent = Color(0xFFFFA62B),
                                    modifier = Modifier.weight(1f),
                                )
                            }
                        }
                        item {
                            SppgInfoBanner(
                                title = "Data mengikuti sumber operasional",
                                message = "Aplikasi tidak membuat angka penerima baru. Ringkasan ini memakai data yang dikirim API SPPG.",
                                icon = Icons.Outlined.Info,
                            )
                        }
                        if (beneficiaryFeatures.isNotEmpty()) {
                            item {
                                DashboardSectionHeader(
                                    title = "Kelola Data",
                                    subtitle = "Buka modul yang berkaitan dengan penerima dan distribusi.",
                                )
                            }
                            items(beneficiaryFeatures, key = { "beneficiary-${it.operationalSlug ?: it.title}" }) { feature ->
                                DashboardFeatureCard(feature, onOpenFieldPlans, onOpenOperational)
                            }
                        }
                    }

                    DashboardTab.Reports -> {
                        item {
                            DashboardSectionHeader(
                                title = "Laporan",
                                subtitle = "Ringkasan kegiatan dan akses laporan yang tersedia.",
                            )
                        }
                        item {
                            Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                                SppgStatCard(
                                    label = "Porsi hari ini",
                                    value = (summary?.portions ?: 0).toString(),
                                    icon = Icons.Outlined.Restaurant,
                                    accent = Color(0xFF22B573),
                                    modifier = Modifier.weight(1f),
                                )
                                SppgStatCard(
                                    label = "Pekerjaan hari ini",
                                    value = operationalState.modules.sumOf { it.todayCount }.toString(),
                                    icon = Icons.Outlined.Assessment,
                                    accent = Color(0xFF7B61FF),
                                    modifier = Modifier.weight(1f),
                                )
                            }
                        }
                        item {
                            DashboardSectionHeader(
                                title = if (reportFeatures.isEmpty()) "Modul Operasional" else "Dokumen & Laporan",
                                subtitle = if (reportFeatures.isEmpty()) {
                                    "Laporan dapat dibuka dari detail pekerjaan pada modul terkait."
                                } else {
                                    "Pilih laporan atau berita acara yang tersedia untuk akun Anda."
                                },
                            )
                        }
                        items(
                            if (reportFeatures.isEmpty()) features else reportFeatures,
                            key = { "report-${it.operationalSlug ?: it.title}" },
                        ) { feature ->
                            DashboardFeatureCard(feature, onOpenFieldPlans, onOpenOperational)
                        }
                    }

                    DashboardTab.Account -> {
                        item {
                            DashboardAccount(
                                session = session,
                                isLoggingOut = isLoggingOut,
                                onOpenTasks = onOpenTasks,
                                onLogout = onLogout,
                            )
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun DashboardTodayMenuCard(summary: MobileDailySummary?) {
    val menus = summary?.menuNames.orEmpty().filter { it.isNotBlank() }
    val darkTheme = MaterialTheme.colorScheme.background.luminance() < 0.5f
    val menuAccent = if (darkTheme) Color(0xFFFFC46B) else Color(0xFFD47A00)
    val successAccent = if (darkTheme) Color(0xFF72D9A3) else Color(0xFF176B43)
    SppgCard(
        shape = RoundedCornerShape(18.dp),
        elevation = CardDefaults.cardElevation(defaultElevation = 0.dp),
    ) {
        Column(Modifier.padding(horizontal = 14.dp, vertical = 12.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text(
                        "Menu Hari Ini",
                        style = MaterialTheme.typography.titleMedium,
                        fontWeight = FontWeight.Bold,
                    )
                    Text(
                        dashboardFullDateLabel(summary?.date),
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }
                Box(
                    modifier = Modifier
                        .background(
                            menuAccent.copy(alpha = if (darkTheme) 0.18f else 0.12f),
                            RoundedCornerShape(999.dp),
                        )
                        .padding(horizontal = 10.dp, vertical = 4.dp),
                ) {
                    Text(
                        if (menus.isEmpty()) "Belum tersedia" else "${menus.size} menu",
                        style = MaterialTheme.typography.labelSmall,
                        color = menuAccent,
                        fontWeight = FontWeight.SemiBold,
                    )
                }
            }
            Spacer(Modifier.height(9.dp))
            Row(verticalAlignment = Alignment.CenterVertically) {
                Image(
                    painter = painterResource(R.drawable.sppg_menu_sample),
                    contentDescription = "Ilustrasi menu hari ini",
                    modifier = Modifier
                        .size(78.dp)
                        .clip(RoundedCornerShape(14.dp)),
                    contentScale = ContentScale.Crop,
                )
                Spacer(Modifier.width(12.dp))
                Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    if (menus.isEmpty()) {
                        Text(
                            "Menu operasional hari ini belum dikirim oleh server.",
                            style = MaterialTheme.typography.bodyMedium,
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                        )
                    } else {
                        menus.forEachIndexed { index, menu ->
                            Row(verticalAlignment = Alignment.Top) {
                                Box(
                                    modifier = Modifier
                                        .padding(top = 7.dp)
                                        .size(6.dp)
                                        .background(successAccent, CircleShape),
                                )
                                Spacer(Modifier.width(8.dp))
                                Text(
                                    menu,
                                    style = MaterialTheme.typography.bodyMedium,
                                    fontWeight = if (index == 0) FontWeight.SemiBold else FontWeight.Normal,
                                    modifier = Modifier.weight(1f),
                                )
                            }
                        }
                    }
                }
            }
            if ((summary?.portions ?: 0) > 0 || (summary?.destinations ?: 0) > 0) {
                Spacer(Modifier.height(9.dp))
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    if ((summary?.portions ?: 0) > 0) {
                        SppgStatusPill(
                            label = "${summary?.portions ?: 0} porsi",
                            colorOverride = successAccent,
                        )
                    }
                    if ((summary?.destinations ?: 0) > 0) {
                        SppgStatusPill(
                            label = "${summary?.destinations ?: 0} tujuan",
                            colorOverride = MaterialTheme.colorScheme.primary,
                        )
                    }
                }
            }
        }
    }
}

private fun dashboardFullDateLabel(value: String?): String {
    val date = value?.takeIf { it.isNotBlank() }?.let { raw ->
        runCatching { LocalDate.parse(raw) }.getOrNull()
    } ?: LocalDate.now()
    val locale = Locale("id", "ID")
    return date.format(DateTimeFormatter.ofPattern("EEEE, dd MMMM yyyy", locale))
        .replaceFirstChar { if (it.isLowerCase()) it.titlecase(locale) else it.toString() }
}

@Composable
private fun DashboardMetricsGrid(
    summary: MobileDailySummary?,
    modules: List<OperationalModule>,
) {
    Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
        Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            SppgStatCard(
                label = "Penerima Manfaat",
                value = (summary?.beneficiaries ?: 0).toString(),
                icon = Icons.Outlined.Groups,
                accent = Color(0xFF22B573),
                modifier = Modifier.weight(1f),
            )
            SppgStatCard(
                label = "Tujuan Layanan",
                value = (summary?.destinations ?: 0).toString(),
                icon = Icons.Outlined.School,
                accent = MaterialTheme.colorScheme.primary,
                modifier = Modifier.weight(1f),
            )
        }
        Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            SppgStatCard(
                label = "Porsi Hari Ini",
                value = (summary?.portions ?: 0).toString(),
                icon = Icons.Outlined.Restaurant,
                accent = Color(0xFFFFA62B),
                modifier = Modifier.weight(1f),
            )
            SppgStatCard(
                label = "Pekerjaan Hari Ini",
                value = modules.sumOf { it.todayCount }.toString(),
                icon = Icons.Outlined.Assessment,
                accent = Color(0xFF7B61FF),
                modifier = Modifier.weight(1f),
            )
        }
    }
}

private fun dashboardDateLabel(value: String?): String? {
    if (value.isNullOrBlank()) return null
    return runCatching {
        val date = LocalDate.parse(value)
        "%02d-%02d-%04d".format(date.dayOfMonth, date.monthValue, date.year)
    }.getOrDefault(value)
}

@Composable
private fun DashboardFeatureCard(
    feature: FeatureItem,
    onOpenFieldPlans: () -> Unit,
    onOpenOperational: (String, String) -> Unit,
) {
    val visual = moduleVisual(feature.visualSlug)
    SppgModuleCard(
        title = feature.title,
        description = feature.description,
        icon = visual.icon,
        accent = visual.color,
        status = feature.status,
        onClick = { openDashboardFeature(feature, onOpenFieldPlans, onOpenOperational) },
    )
}

private fun dashboardFeatureGroups(features: List<FeatureItem>): List<FeatureGroup> {
    fun groupFor(feature: FeatureItem): String = when {
        feature.visualSlug == "field-plans" || feature.operationalSlug?.startsWith("lapangan") == true -> "field"
        feature.operationalSlug?.startsWith("gizi") == true -> "nutrition"
        feature.operationalSlug?.startsWith("gudang") == true || feature.operationalSlug in setOf("penerimaan", "kartu-stok", "kontrol-stok") -> "warehouse"
        feature.operationalSlug in setOf("persiapan", "pengolahan", "pemorsian") -> "kitchen"
        feature.operationalSlug in setOf("distribusi", "pengambilan-ompreng") -> "distribution"
        feature.operationalSlug in setOf("pencucian", "kebersihan") -> "sanitation"
        feature.operationalSlug == "keamanan" -> "security"
        feature.operationalSlug?.contains("presensi") == true -> "attendance"
        else -> "other"
    }
    val metadata = linkedMapOf(
        "nutrition" to Triple("Ahli Gizi", "Perencanaan menu, gizi, dan kebutuhan bahan", "field-plans"),
        "warehouse" to Triple("Gudang", "Penerimaan, pengambilan, dan kontrol stok", "gudang"),
        "kitchen" to Triple("Operasional Dapur", "Persiapan, pengolahan, dan pemorsian", "pengolahan"),
        "field" to Triple("Asisten Lapangan", "Rencana distribusi, laporan, dan insiden", "lapangan-laporan"),
        "distribution" to Triple("Distribusi", "Pengantaran dan pengambilan ompreng", "distribusi"),
        "sanitation" to Triple("Sanitasi", "Pencucian ompreng dan kebersihan", "pencucian"),
        "security" to Triple("Keamanan", "Laporan situasi dan insiden", "keamanan"),
        "attendance" to Triple("Presensi", "Kehadiran relawan", "presensi"),
        "other" to Triple("Lainnya", "Fungsi pendukung operasional", "tasks"),
    )
    return features.groupBy(::groupFor).mapNotNull { (key, items) ->
        val data = metadata[key] ?: return@mapNotNull null
        FeatureGroup(key, data.first, data.second, data.third, items)
    }
}

private fun openDashboardFeature(
    feature: FeatureItem,
    onOpenFieldPlans: () -> Unit,
    onOpenOperational: (String, String) -> Unit,
) {
    if (!feature.isAvailable) return
    if (feature.operationalSlug != null) {
        onOpenOperational(feature.operationalSlug, feature.operationalLabel.orEmpty())
    } else {
        onOpenFieldPlans()
    }
}

@Composable
private fun DashboardGreeting(session: UserSession) {
    Row(verticalAlignment = Alignment.CenterVertically) {
        Icon(Icons.Outlined.AccountCircle, contentDescription = null,
            tint = MaterialTheme.colorScheme.primary, modifier = Modifier.size(44.dp))
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f)) {
        Text("Halo, selamat bekerja", color = MaterialTheme.colorScheme.onSurfaceVariant)
        Text(session.userName, style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.SemiBold)
        Spacer(Modifier.height(4.dp))
        Text(session.roleLabel, color = MaterialTheme.colorScheme.primary, style = MaterialTheme.typography.labelLarge)
        }
    }
}

@Composable
private fun DashboardDailySummary(
    summary: MobileDailySummary?,
    session: UserSession,
    modules: List<OperationalModule>,
) {
    val role = session.role.lowercase()
    val focusModule = when {
        role.contains("gudang") -> modules.firstOrNull { it.slug == "gudang" }
        role.contains("persiapan") -> modules.firstOrNull { it.slug == "persiapan" }
        role.contains("pengolahan") -> modules.firstOrNull { it.slug == "pengolahan" }
        role.contains("pemorsian") -> modules.firstOrNull { it.slug == "pemorsian" }
        role.contains("pencucian") -> modules.firstOrNull { it.slug == "pencucian" }
        role.contains("keamanan") || role.contains("satpam") -> modules.firstOrNull { it.slug == "keamanan" }
        else -> null
    }
    val menuText = summary?.menuNames?.joinToString(", ")?.ifBlank { null } ?: "Belum ada menu aktif"
    val thirdLabel = focusModule?.label ?: "Tujuan distribusi"
    val thirdValue = focusModule?.todayCount ?: (summary?.destinations ?: 0)

    SppgCard(
        modifier = Modifier.fillMaxWidth(),
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
        shape = RoundedCornerShape(16.dp),
    ) {
        Column(Modifier.padding(18.dp)) {
            Text("RINGKASAN HARI INI", color = MaterialTheme.colorScheme.onSurfaceVariant, style = MaterialTheme.typography.labelMedium)
            Spacer(Modifier.height(7.dp))
            Text(menuText, color = MaterialTheme.colorScheme.onSurface, fontWeight = FontWeight.Bold, style = MaterialTheme.typography.titleMedium)
            Text("Menu yang diolah hari ini", color = MaterialTheme.colorScheme.onSurfaceVariant, style = MaterialTheme.typography.bodySmall)
            Spacer(Modifier.height(16.dp))
            FlowRow(horizontalArrangement = Arrangement.spacedBy(10.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                DailyMetric("Penerima", summary?.beneficiaries ?: 0, Modifier.weight(1f))
                DailyMetric("Porsi", summary?.portions ?: 0, Modifier.weight(1f))
            }
            Spacer(Modifier.height(10.dp))
            FlowRow(horizontalArrangement = Arrangement.spacedBy(10.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                DailyMetric(thirdLabel, thirdValue, Modifier.weight(1f))
                DailyMetric("Menu hari ini", summary?.menuNames?.size ?: 0, Modifier.weight(1f))
            }
        }
    }
}

@Composable
private fun DailyMetric(label: String, value: Int, modifier: Modifier = Modifier) {
    Column(
        modifier = modifier.background(MaterialTheme.colorScheme.primaryContainer, RoundedCornerShape(14.dp)).padding(11.dp),
    ) {
        Text(value.toString(), color = MaterialTheme.colorScheme.primary, style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
        Text(label, color = MaterialTheme.colorScheme.onSurfaceVariant, style = MaterialTheme.typography.labelSmall)
    }
}

@Composable
private fun DashboardSectionHeader(title: String, subtitle: String) {
    Column {
        Text(
            title,
            color = MaterialTheme.colorScheme.onSurface,
            style = MaterialTheme.typography.titleMedium,
            fontWeight = FontWeight.SemiBold,
        )
        Spacer(Modifier.height(3.dp))
        Text(
            subtitle,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
            style = MaterialTheme.typography.bodySmall,
        )
    }
}

@Composable
private fun DashboardModuleGroup(
    group: FeatureGroup,
    initiallyExpanded: Boolean = false,
    onOpenFeature: (FeatureItem) -> Unit,
) {
    var expanded by remember(group.key) { mutableStateOf(initiallyExpanded) }
    val clusters = remember(group.items) { compactFeatureClusters(group.items) }
    SppgCard(
        modifier = Modifier.fillMaxWidth(),
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
        shape = RoundedCornerShape(16.dp),
        border = androidx.compose.foundation.BorderStroke(1.dp, MaterialTheme.colorScheme.outlineVariant),
    ) {
        Column {
            Row(
                modifier = Modifier.fillMaxWidth().clickable { expanded = !expanded }.padding(15.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                ModuleIcon(group.visualSlug, Modifier.size(42.dp))
                Spacer(Modifier.width(13.dp))
                Column(Modifier.weight(1f)) {
                    Text(group.title, fontWeight = FontWeight.SemiBold)
                    Text(group.description, color = MaterialTheme.colorScheme.onSurfaceVariant, style = MaterialTheme.typography.bodySmall)
                }
                Icon(
                    if (expanded) Icons.Outlined.ExpandLess else Icons.Outlined.ExpandMore,
                    contentDescription = if (expanded) "Tutup" else "Buka",
                    tint = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
            if (expanded) {
                clusters.forEach { cluster ->
                    DashboardFeatureCluster(cluster, onOpenFeature)
                }
            }
        }
    }
}

private fun compactFeatureClusters(items: List<FeatureItem>): List<FeatureCluster> {
    fun baseTitle(title: String): String = title
        .removeSuffix(" Non-Pangan")
        .removeSuffix(" Pangan")
        .trim()

    return items
        .groupBy { baseTitle(it.title) }
        .map { (title, groupedItems) ->
            FeatureCluster(
                key = groupedItems.joinToString("|") { it.operationalSlug ?: it.title },
                title = title,
                items = groupedItems,
            )
        }
}

@Composable
private fun DashboardFeatureCluster(
    cluster: FeatureCluster,
    onOpenFeature: (FeatureItem) -> Unit,
) {
    if (cluster.items.size == 1) {
        val feature = cluster.items.first()
        Row(
            modifier = Modifier
                .fillMaxWidth()
                .clickable(enabled = feature.isAvailable) { onOpenFeature(feature) }
                .padding(start = 70.dp, top = 12.dp, end = 15.dp, bottom = 12.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Column(Modifier.weight(1f)) {
                Text(feature.title, style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.Medium)
                Text(feature.status, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
            }
            Icon(Icons.AutoMirrored.Outlined.ArrowForward, contentDescription = null, tint = MaterialTheme.colorScheme.outline)
        }
        return
    }

    var expanded by remember(cluster.key) { mutableStateOf(false) }
    val totalCount = cluster.items.sumOf { feature ->
        feature.status.substringBefore(' ').toIntOrNull() ?: 0
    }
    Column {
        Row(
            modifier = Modifier
                .fillMaxWidth()
                .clickable { expanded = !expanded }
                .padding(start = 70.dp, top = 12.dp, end = 15.dp, bottom = 12.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Column(Modifier.weight(1f)) {
                Text(cluster.title, style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.Medium)
                Text(
                    "Pangan & non-pangan • $totalCount pekerjaan",
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
            Icon(
                if (expanded) Icons.Outlined.ExpandLess else Icons.Outlined.ExpandMore,
                contentDescription = if (expanded) "Tutup pilihan" else "Buka pilihan",
                tint = MaterialTheme.colorScheme.outline,
            )
        }
        if (expanded) {
            cluster.items.forEach { feature ->
                val variant = when {
                    feature.title.endsWith(" Non-Pangan") -> "Non-Pangan"
                    feature.title.endsWith(" Pangan") -> "Pangan"
                    else -> feature.title
                }
                Row(
                    modifier = Modifier
                        .fillMaxWidth()
                        .clickable(enabled = feature.isAvailable) { onOpenFeature(feature) }
                        .heightIn(min = 48.dp)
                        .padding(start = 40.dp, top = 12.dp, end = 16.dp, bottom = 12.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Column(Modifier.weight(1f)) {
                        Text(variant, style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold)
                        Text(feature.status, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                    }
                    Icon(
                        Icons.AutoMirrored.Outlined.ArrowForward,
                        contentDescription = null,
                        modifier = Modifier.size(18.dp),
                        tint = MaterialTheme.colorScheme.outline,
                    )
                }
            }
        }
    }
}

@Composable
private fun DashboardAccount(
    session: UserSession,
    isLoggingOut: Boolean,
    onOpenTasks: () -> Unit,
    onLogout: () -> Unit,
) {
    Column(verticalArrangement = Arrangement.spacedBy(14.dp)) {
        DashboardSectionHeader("Profil", "Informasi akun dan pengaturan aplikasi.")

        SppgCard(
            modifier = Modifier.fillMaxWidth(),
            shape = RoundedCornerShape(18.dp),
            elevation = CardDefaults.cardElevation(defaultElevation = 0.dp),
        ) {
            Column(
                modifier = Modifier.fillMaxWidth().padding(20.dp),
                horizontalAlignment = Alignment.CenterHorizontally,
            ) {
                Image(
                    painter = painterResource(R.drawable.sppg_avatar_staff_male),
                    contentDescription = "Avatar pengguna",
                    modifier = Modifier.size(92.dp).clip(CircleShape),
                    contentScale = ContentScale.Crop,
                )
                Spacer(Modifier.height(12.dp))
                Text(
                    session.userName,
                    style = MaterialTheme.typography.titleLarge,
                    fontWeight = FontWeight.Bold,
                )
                Text(
                    session.roleLabel,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                    style = MaterialTheme.typography.bodyMedium,
                )
                Text(
                    session.unitName.ifBlank { "SPPG" },
                    color = MaterialTheme.colorScheme.primary,
                    style = MaterialTheme.typography.labelMedium,
                    modifier = Modifier.padding(top = 4.dp),
                )
            }
        }

        SppgCard(
            modifier = Modifier.fillMaxWidth(),
            shape = RoundedCornerShape(18.dp),
            elevation = CardDefaults.cardElevation(defaultElevation = 0.dp),
        ) {
            Column(Modifier.padding(horizontal = 14.dp, vertical = 8.dp)) {
                SppgProfileMenuRow(
                    label = "Nomor pegawai",
                    icon = Icons.Outlined.Badge,
                    value = session.employeeNumber.ifBlank { "-" },
                )
                SppgProfileMenuRow(
                    label = "Email",
                    icon = Icons.Outlined.AccountCircle,
                    value = session.email.ifBlank { "-" },
                )
                SppgProfileMenuRow(
                    label = "Notifikasi",
                    icon = Icons.Outlined.Notifications,
                    onClick = onOpenTasks,
                )
            }
        }

        SppgInfoBanner(
            title = "Tentang SPPG",
            message = "Satuan Pelayanan Pemenuhan Gizi — aplikasi operasional untuk mendukung pelayanan program gizi.",
            icon = Icons.Outlined.Info,
        )

        SppgOutlinedButton(
            onClick = onLogout,
            enabled = !isLoggingOut,
            border = androidx.compose.foundation.BorderStroke(1.dp, MaterialTheme.colorScheme.error),
            modifier = Modifier.fillMaxWidth().heightIn(min = 52.dp),
        ) {
            if (isLoggingOut) {
                CircularProgressIndicator(
                    modifier = Modifier.size(20.dp),
                    strokeWidth = 2.dp,
                    color = MaterialTheme.colorScheme.error,
                )
            } else {
                Icon(
                    Icons.AutoMirrored.Outlined.Logout,
                    contentDescription = null,
                    tint = MaterialTheme.colorScheme.error,
                )
            }
            Spacer(Modifier.width(8.dp))
            Text(
                if (isLoggingOut) "Keluar…" else "Keluar",
                color = MaterialTheme.colorScheme.error,
                fontWeight = FontWeight.SemiBold,
            )
        }
    }
}

@Composable
private fun AccountRow(label: String, value: String) {
    Row(Modifier.fillMaxWidth().padding(vertical = 9.dp)) {
        Text(label, modifier = Modifier.weight(1f), color = MaterialTheme.colorScheme.onSurfaceVariant)
        Text(value, modifier = Modifier.weight(1.2f), fontWeight = FontWeight.Medium)
    }
}

@Composable
private fun UnsupportedRoleCard() {
    SppgCard(
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
        shape = RoundedCornerShape(16.dp),
    ) {
        Column(modifier = Modifier.padding(20.dp)) {
            Text("Peran belum tersedia", fontWeight = FontWeight.Bold)
            Spacer(Modifier.height(8.dp))
            Text(
                "Akun ini belum memiliki ruang kerja mobile yang diizinkan.",
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }
    }
}

private fun shareDocument(
    context: Context,
    file: File,
    mimeType: String,
    chooserTitle: String,
) {
    runCatching {
        val uri = FileProvider.getUriForFile(
            context,
            "${context.packageName}.fileprovider",
            file,
        )
        val intent = Intent(Intent.ACTION_SEND).apply {
            type = mimeType
            putExtra(Intent.EXTRA_STREAM, uri)
            clipData = ClipData.newRawUri(file.name, uri)
            addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
        }
        context.startActivity(Intent.createChooser(intent, chooserTitle))
    }.onFailure {
        Toast.makeText(
            context,
            "Tidak ada aplikasi yang dapat membagikan dokumen ini.",
            Toast.LENGTH_LONG,
        ).show()
    }
}
