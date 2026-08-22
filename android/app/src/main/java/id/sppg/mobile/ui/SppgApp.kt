package id.sppg.mobile.ui

import android.content.ClipData
import android.content.Context
import android.content.Intent
import android.widget.Toast
import androidx.activity.compose.BackHandler
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.Image
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
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.windowInsetsPadding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.Logout
import androidx.compose.material.icons.automirrored.outlined.ArrowForward
import androidx.compose.material.icons.outlined.AccountCircle
import androidx.compose.material.icons.outlined.Dashboard
import androidx.compose.material.icons.outlined.ExpandLess
import androidx.compose.material.icons.outlined.ExpandMore
import androidx.compose.material.icons.outlined.Home
import androidx.compose.material.icons.outlined.Notifications
import androidx.compose.material.icons.outlined.Restaurant
import androidx.compose.material.icons.outlined.Visibility
import androidx.compose.material.icons.outlined.VisibilityOff
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.NavigationBarItemDefaults
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.platform.LocalFocusManager
import androidx.compose.ui.text.font.FontWeight
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
import id.sppg.mobile.ui.theme.ForestDark
import id.sppg.mobile.ui.theme.Leaf
import id.sppg.mobile.ui.theme.Navy
import kotlinx.coroutines.flow.collect
import java.io.File
import java.time.LocalDate

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

@Composable
fun SppgApp(
    authViewModel: AuthViewModel,
    fieldPlanViewModel: FieldPlanViewModel,
    operationalViewModel: OperationalViewModel,
    notificationViewModel: NotificationViewModel,
    securityViewModel: SecurityViewModel,
) {
    val state by authViewModel.uiState.collectAsStateWithLifecycle()

    SppgTheme {
        when {
            state.isLoading -> LoadingScreen()
            state.session == null -> LoginScreen(
                isSubmitting = state.isSubmitting,
                errorMessage = state.errorMessage,
                onLogin = authViewModel::login,
                onDismissError = authViewModel::dismissError,
            )
            else -> AuthenticatedContent(
                session = requireNotNull(state.session),
                isLoggingOut = state.isSubmitting,
                noticeMessage = state.noticeMessage,
                onDismissNotice = authViewModel::dismissNotice,
                onLogout = { notificationViewModel.unregisterDevice(authViewModel::logout) },
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
            screen = when (event.screen) {
                "security" -> AppScreen.Security
                "tasks", "notifications" -> AppScreen.Tasks
                else -> AppScreen.Dashboard
            }
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
                notificationViewModel.markRead(notification) { target ->
                    screen = if (target == "security") AppScreen.Security else AppScreen.Tasks
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
                if (operationalState.activeModule == it) {
                    operationalViewModel.refreshRecords()
                } else {
                    operationalViewModel.loadRecords(
                        it,
                        date = if (it == "gudang-stok") null else LocalDate.now().toString(),
                    )
                }
            },
            onRefresh = operationalViewModel::refreshRecords,
            onFilterChange = operationalViewModel::filterRecords,
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
}

@Composable
private fun LoadingScreen() {
    Box(
        modifier = Modifier
            .fillMaxSize()
            .background(MaterialTheme.colorScheme.background),
        contentAlignment = Alignment.Center,
    ) {
        CircularProgressIndicator()
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
        contentPadding = PaddingValues(horizontal = 20.dp, vertical = 28.dp),
        verticalArrangement = Arrangement.Center,
    ) {
        item {
            Column {
                Card(
                    colors = CardDefaults.cardColors(containerColor = Navy),
                    shape = RoundedCornerShape(24.dp),
                ) {
                    Column(Modifier.fillMaxWidth().padding(24.dp)) {
                        Box(
                            modifier = Modifier.size(52.dp).background(Color.White, RoundedCornerShape(16.dp)),
                            contentAlignment = Alignment.Center,
                        ) {
                            Image(
                                painter = painterResource(R.drawable.logo_bgn),
                                contentDescription = "Logo Badan Gizi Nasional",
                                modifier = Modifier.fillMaxSize().padding(3.dp),
                                contentScale = ContentScale.Fit,
                            )
                        }
                        Spacer(Modifier.height(22.dp))
                        Text("SPPG Mobile", color = Color.White, style = MaterialTheme.typography.headlineMedium)
                        Spacer(Modifier.height(6.dp))
                        Text(
                            "Sistem operasional terpadu Program Makan Bergizi Gratis.",
                            color = Color.White.copy(alpha = .7f),
                            style = MaterialTheme.typography.bodyMedium,
                        )
                    }
                }
                Spacer(Modifier.height(24.dp))
                Card(
                    colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
                    shape = RoundedCornerShape(22.dp),
                    border = androidx.compose.foundation.BorderStroke(1.dp, MaterialTheme.colorScheme.outlineVariant),
                ) {
                    Column(Modifier.padding(20.dp)) {
                        Text("Masuk ke akun", style = MaterialTheme.typography.titleLarge)
                        Spacer(Modifier.height(5.dp))
                        Text("Gunakan akun yang sama dengan website SPPG.", color = MaterialTheme.colorScheme.onSurfaceVariant)
                        Spacer(Modifier.height(20.dp))
                        OutlinedTextField(
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
                        OutlinedTextField(
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
                            Text(errorMessage, color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodyMedium)
                        }
                        Spacer(Modifier.height(20.dp))
                        Button(
                            onClick = {
                                focusManager.clearFocus()
                                onLogin(login, password)
                            },
                            modifier = Modifier.fillMaxWidth().height(52.dp),
                            enabled = !isSubmitting,
                            shape = RoundedCornerShape(14.dp),
                        ) {
                            if (isSubmitting) {
                                CircularProgressIndicator(
                                    modifier = Modifier.size(22.dp),
                                    color = MaterialTheme.colorScheme.onPrimary,
                                    strokeWidth = 2.dp,
                                )
                            } else Text("Masuk", fontWeight = FontWeight.SemiBold)
                        }
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

private enum class DashboardTab { Home, Modules, Account }

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
            add(FeatureItem("Rencana distribusi", "Buat rencana, konfirmasi penerima, atur rute, dan aktivasi.", "Siap digunakan", isAvailable = true))
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

    val groups = remember(features) { dashboardFeatureGroups(features) }

    Scaffold(
        containerColor = MaterialTheme.colorScheme.background,
        topBar = {
            TopAppBar(
                title = {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Box(
                            modifier = Modifier
                                .size(38.dp)
                                .background(Color.White, RoundedCornerShape(12.dp)),
                            contentAlignment = Alignment.Center,
                        ) {
                            Image(
                                painter = painterResource(R.drawable.logo_bgn),
                                contentDescription = "Logo Badan Gizi Nasional",
                                modifier = Modifier.fillMaxSize().padding(2.dp),
                                contentScale = ContentScale.Fit,
                            )
                        }
                        Spacer(Modifier.width(11.dp))
                        Column {
                            Text("SPPG", fontWeight = FontWeight.Bold, style = MaterialTheme.typography.titleMedium)
                            Text(
                                session.unitName.ifBlank { "Operasional MBG" },
                                color = Color.White.copy(alpha = 0.68f),
                                style = MaterialTheme.typography.bodySmall,
                                maxLines = 1,
                            )
                        }
                    }
                },
                actions = {
                    Box {
                        IconButton(onClick = onOpenTasks) {
                            Icon(Icons.Outlined.Notifications, contentDescription = "Notifikasi")
                        }
                        if (unreadNotificationCount > 0) {
                            Box(
                                Modifier
                                    .align(Alignment.TopEnd)
                                    .padding(top = 9.dp, end = 9.dp)
                                    .size(9.dp)
                                    .background(MaterialTheme.colorScheme.secondary, CircleShape),
                            )
                        }
                    }
                },
                colors = sppgTopAppBarColors(),
            )
        },
        bottomBar = {
            NavigationBar(containerColor = MaterialTheme.colorScheme.surface) {
                listOf(
                    Triple(DashboardTab.Home, "Beranda", Icons.Outlined.Home),
                    Triple(DashboardTab.Modules, "Modul", Icons.Outlined.Dashboard),
                    Triple(DashboardTab.Account, "Akun", Icons.Outlined.AccountCircle),
                ).forEach { (tab, label, icon) ->
                    NavigationBarItem(
                        selected = selectedTab == tab,
                        onClick = { selectedTab = tab },
                        icon = { Icon(icon, contentDescription = null) },
                        label = { Text(label) },
                        colors = NavigationBarItemDefaults.colors(
                            selectedIconColor = MaterialTheme.colorScheme.primary,
                            selectedTextColor = MaterialTheme.colorScheme.primary,
                            indicatorColor = MaterialTheme.colorScheme.primaryContainer,
                        ),
                    )
                }
            }
        },
    ) { innerPadding ->
        LazyColumn(
            modifier = Modifier.fillMaxSize(),
            contentPadding = PaddingValues(
                start = 20.dp,
                top = innerPadding.calculateTopPadding() + 16.dp,
                end = 20.dp,
                // The bottom navigation is part of the Scaffold. Include its
                // padding so the final module remains reachable and visible.
                bottom = innerPadding.calculateBottomPadding() + 24.dp,
            ),
            verticalArrangement = Arrangement.spacedBy(14.dp),
        ) {
            if (!noticeMessage.isNullOrBlank()) {
                item {
                    Card(
                        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.secondaryContainer),
                        shape = RoundedCornerShape(16.dp),
                    ) {
                        Row(Modifier.padding(16.dp), verticalAlignment = Alignment.CenterVertically) {
                            Text(noticeMessage, modifier = Modifier.weight(1f), style = MaterialTheme.typography.bodySmall)
                            TextButton(onClick = onDismissNotice) { Text("Tutup") }
                        }
                    }
                }
            }

            if (operationalState.isLoading && operationalState.modules.isEmpty()) {
                item {
                    Box(modifier = Modifier.fillMaxWidth().padding(24.dp), contentAlignment = Alignment.Center) {
                        CircularProgressIndicator()
                    }
                }
            } else if (operationalState.errorMessage != null && operationalState.modules.isEmpty()) {
                item {
                    Card(shape = RoundedCornerShape(18.dp)) {
                        Column(modifier = Modifier.padding(20.dp)) {
                            Text("Ruang kerja belum dapat dimuat", fontWeight = FontWeight.Bold)
                            Spacer(Modifier.height(8.dp))
                            Text(
                                operationalState.errorMessage,
                                color = MaterialTheme.colorScheme.onSurfaceVariant,
                            )
                            TextButton(onClick = { onLoadOperationalModules(true) }) { Text("Coba lagi") }
                        }
                    }
                }
            } else if (features.isEmpty() && selectedTab != DashboardTab.Account) {
                item { UnsupportedRoleCard() }
            } else {
                when (selectedTab) {
                    DashboardTab.Home -> {
                        item {
                            DashboardGreeting(session)
                            Spacer(Modifier.height(16.dp))
                            DashboardDailySummary(
                                summary = operationalState.dailySummary,
                                session = session,
                                modules = operationalState.modules,
                            )
                            Spacer(Modifier.height(22.dp))
                            DashboardSectionHeader("MENU SESUAI AKSES ANDA", "Pilih kelompok untuk membuka pekerjaan.")
                        }
                        items(groups, key = { "home-group-${it.key}" }) { group ->
                            DashboardModuleGroup(
                                group = group,
                                onOpenFeature = { feature ->
                                    openDashboardFeature(feature, onOpenFieldPlans, onOpenOperational)
                                },
                            )
                        }
                    }
                    DashboardTab.Modules -> {
                        item { DashboardSectionHeader("SELURUH MODUL", "Modul dikelompokkan berdasarkan fungsi pekerjaan.") }
                        items(groups, key = { "module-group-${it.key}" }) { group ->
                            DashboardModuleGroup(
                                group = group,
                                initiallyExpanded = false,
                                onOpenFeature = { feature ->
                                    openDashboardFeature(feature, onOpenFieldPlans, onOpenOperational)
                                },
                            )
                        }
                    }
                    DashboardTab.Account -> {
                        item { DashboardAccount(session, isLoggingOut, onLogout) }
                    }
                }
            }
        }
    }
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
    Column {
        Text("Selamat bekerja,", color = MaterialTheme.colorScheme.onSurfaceVariant)
        Text(session.userName, style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.SemiBold)
        Spacer(Modifier.height(4.dp))
        Text(session.roleLabel, color = MaterialTheme.colorScheme.primary, style = MaterialTheme.typography.labelLarge)
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

    Card(
        modifier = Modifier.fillMaxWidth(),
        colors = CardDefaults.cardColors(containerColor = Navy),
        shape = RoundedCornerShape(20.dp),
    ) {
        Column(Modifier.padding(18.dp)) {
            Text("RINGKASAN HARI INI", color = Color.White.copy(alpha = .68f), style = MaterialTheme.typography.labelMedium)
            Spacer(Modifier.height(7.dp))
            Text(menuText, color = Color.White, fontWeight = FontWeight.Bold, style = MaterialTheme.typography.titleMedium)
            Text("Menu yang diolah hari ini", color = Color.White.copy(alpha = .68f), style = MaterialTheme.typography.bodySmall)
            Spacer(Modifier.height(16.dp))
            Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                DailyMetric("Penerima", summary?.beneficiaries ?: 0, Modifier.weight(1f))
                DailyMetric("Porsi", summary?.portions ?: 0, Modifier.weight(1f))
                DailyMetric(thirdLabel, thirdValue, Modifier.weight(1f))
            }
        }
    }
}

@Composable
private fun DailyMetric(label: String, value: Int, modifier: Modifier = Modifier) {
    Column(
        modifier = modifier.background(Color.White.copy(alpha = .09f), RoundedCornerShape(14.dp)).padding(11.dp),
    ) {
        Text(value.toString(), color = Color.White, fontWeight = FontWeight.Bold)
        Text(label, color = Color.White.copy(alpha = .7f), style = MaterialTheme.typography.labelSmall, maxLines = 2)
    }
}

@Composable
private fun DashboardSectionHeader(title: String, subtitle: String) {
    Column {
        Text(title, color = MaterialTheme.colorScheme.primary, style = MaterialTheme.typography.labelMedium, fontWeight = FontWeight.Bold)
        Spacer(Modifier.height(4.dp))
        Text(subtitle, color = MaterialTheme.colorScheme.onSurfaceVariant, style = MaterialTheme.typography.bodySmall)
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
    Card(
        modifier = Modifier.fillMaxWidth(),
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
        shape = RoundedCornerShape(18.dp),
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
                        .padding(start = 86.dp, top = 10.dp, end = 15.dp, bottom = 10.dp),
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
private fun DashboardAccount(session: UserSession, isLoggingOut: Boolean, onLogout: () -> Unit) {
    Column(verticalArrangement = Arrangement.spacedBy(14.dp)) {
        DashboardSectionHeader("AKUN", "Identitas pengguna yang sedang aktif.")
        Card(
            colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
            shape = RoundedCornerShape(20.dp),
            border = androidx.compose.foundation.BorderStroke(1.dp, MaterialTheme.colorScheme.outlineVariant),
        ) {
            Column(Modifier.padding(20.dp)) {
                Text(session.userName, style = MaterialTheme.typography.titleLarge)
                Text(session.roleLabel, color = MaterialTheme.colorScheme.primary)
                Spacer(Modifier.height(18.dp))
                AccountRow("Unit", session.unitName.ifBlank { "-" })
                AccountRow("Nomor pegawai", session.employeeNumber.ifBlank { "-" })
                AccountRow("Email", session.email.ifBlank { "-" })
                Spacer(Modifier.height(18.dp))
                Button(onClick = onLogout, enabled = !isLoggingOut, modifier = Modifier.fillMaxWidth()) {
                    Icon(Icons.AutoMirrored.Outlined.Logout, contentDescription = null)
                    Spacer(Modifier.width(8.dp))
                    Text("Keluar dari akun")
                }
            }
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
private fun DashboardHero(session: UserSession) {
    Card(
        modifier = Modifier.fillMaxWidth(),
        shape = RoundedCornerShape(26.dp),
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.primary),
        elevation = CardDefaults.cardElevation(defaultElevation = 3.dp),
    ) {
        Box(
            modifier = Modifier
                .fillMaxWidth()
                .background(
                    Brush.linearGradient(
                        listOf(MaterialTheme.colorScheme.primary, ForestDark),
                    ),
                )
                .padding(22.dp),
        ) {
            Column {
                Text(
                    "Selamat bekerja,",
                    color = Color.White.copy(alpha = 0.76f),
                    style = MaterialTheme.typography.bodyMedium,
                )
                Spacer(Modifier.height(4.dp))
                Text(
                    session.userName,
                    color = Color.White,
                    style = MaterialTheme.typography.headlineMedium,
                    fontWeight = FontWeight.ExtraBold,
                )
                Spacer(Modifier.height(14.dp))
                RoleBadge(session.roleLabel)
                if (session.unitName.isNotBlank()) {
                    Spacer(Modifier.height(14.dp))
                    Text(
                        session.unitName,
                        color = Color.White.copy(alpha = 0.8f),
                        style = MaterialTheme.typography.labelMedium,
                    )
                }
            }
            Box(
                Modifier
                    .align(Alignment.TopEnd)
                    .size(74.dp)
                    .background(Color.White.copy(alpha = 0.08f), CircleShape),
            )
        }
    }
}

@Composable
private fun RoleBadge(roleLabel: String) {
    Row(
        modifier = Modifier
            .background(Color.White.copy(alpha = 0.15f), RoundedCornerShape(50))
            .padding(horizontal = 12.dp, vertical = 7.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(
            modifier = Modifier
                .size(8.dp)
                .background(MaterialTheme.colorScheme.secondary, CircleShape),
        )
        Spacer(Modifier.width(8.dp))
        Text(
            text = roleLabel.ifBlank { "Pengguna SPPG" },
            color = Color.White,
            style = MaterialTheme.typography.labelLarge,
        )
    }
}

@Composable
private fun FeatureCard(
    feature: FeatureItem,
    onClick: (() -> Unit)?,
) {
    Card(
        modifier = Modifier
            .fillMaxWidth()
            .clickable(enabled = onClick != null) { onClick?.invoke() },
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
        elevation = CardDefaults.cardElevation(defaultElevation = 2.dp),
        shape = RoundedCornerShape(22.dp),
    ) {
        Row(
            modifier = Modifier.padding(18.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
            ModuleIcon(feature.visualSlug)
            Spacer(Modifier.width(16.dp))
            Column(modifier = Modifier.weight(1f)) {
                Text(feature.title, fontWeight = FontWeight.Bold, style = MaterialTheme.typography.titleMedium)
                Spacer(Modifier.height(5.dp))
                Text(
                    feature.description,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                    style = MaterialTheme.typography.bodyMedium,
                )
                Spacer(Modifier.height(11.dp))
                SppgStatusPill(feature.status)
            }
            Spacer(Modifier.width(8.dp))
            Icon(
                Icons.AutoMirrored.Outlined.ArrowForward,
                contentDescription = null,
                tint = MaterialTheme.colorScheme.outline,
            )
        }
    }
}

@Composable
private fun UnsupportedRoleCard() {
    Card(
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
        shape = RoundedCornerShape(18.dp),
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
