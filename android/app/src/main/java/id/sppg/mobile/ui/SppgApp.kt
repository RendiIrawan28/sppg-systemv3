package id.sppg.mobile.ui

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
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
import androidx.compose.ui.platform.LocalFocusManager
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.input.VisualTransformation
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import id.sppg.mobile.core.notification.NotificationNavigationStore
import id.sppg.mobile.data.session.UserSession
import id.sppg.mobile.ui.theme.SppgTheme
import id.sppg.mobile.ui.theme.ForestDark
import id.sppg.mobile.ui.theme.Leaf

private sealed interface AppScreen {
    data object Dashboard : AppScreen
    data object Tasks : AppScreen
    data object Security : AppScreen
    data object FieldPlans : AppScreen
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

    val notificationNavigation by NotificationNavigationStore.event.collectAsStateWithLifecycle()
    LaunchedEffect(notificationNavigation, session.token) {
        notificationNavigation?.let { event ->
            screen = when (event.screen) {
                "security" -> AppScreen.Security
                "tasks", "notifications" -> AppScreen.Tasks
                else -> AppScreen.Dashboard
            }
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
            pendingTaskCount = notificationState.tasks.size,
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
            onStartShift = securityViewModel::startShift,
            onSubmitReport = securityViewModel::submitReport,
            onClearFeedback = securityViewModel::clearFeedback,
        )
        AppScreen.FieldPlans -> FieldPlanListScreen(
            state = fieldPlanState,
            onBack = { screen = AppScreen.Dashboard },
            onRefresh = { fieldPlanViewModel.loadPlans(force = true) },
            onLoad = fieldPlanViewModel::loadPlans,
            onPlanClick = { screen = AppScreen.FieldPlanDetail(it) },
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
            onLoad = { operationalViewModel.loadRecords(it) },
            onRefresh = { operationalViewModel.loadRecords(current.slug, force = true) },
            onRecordClick = {
                screen = AppScreen.OperationalDetail(current.slug, current.label, it)
            },
            onCreate = {
                operationalViewModel.prepareCreate(current.slug)
                if (current.slug == "keamanan") {
                    operationalViewModel.createRecord(current.slug) { id ->
                        screen = AppScreen.OperationalDetail(current.slug, current.label, id)
                    }
                } else {
                    screen = AppScreen.OperationalCreate(current.slug, current.label)
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
            onAction = { action, notes ->
                operationalViewModel.runAction(current.slug, current.id, action, notes)
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
            onRelationAction = { section, item, action ->
                operationalViewModel.runRelationAction(
                    current.slug, current.id, section.key, item.id, action,
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
            .background(
                Brush.verticalGradient(
                    listOf(
                        MaterialTheme.colorScheme.primary.copy(alpha = 0.14f),
                        MaterialTheme.colorScheme.background,
                        MaterialTheme.colorScheme.background,
                    ),
                ),
            )
            .windowInsetsPadding(WindowInsets.safeDrawing),
        contentPadding = PaddingValues(horizontal = 24.dp, vertical = 32.dp),
        verticalArrangement = Arrangement.Center,
    ) {
        item {
            Box(
                modifier = Modifier
                    .size(72.dp)
                    .background(
                        Brush.linearGradient(listOf(Leaf, ForestDark)),
                        RoundedCornerShape(22.dp),
                    ),
                contentAlignment = Alignment.Center,
            ) {
                Icon(
                    Icons.Outlined.Restaurant,
                    contentDescription = null,
                    tint = Color.White,
                    modifier = Modifier.size(34.dp),
                )
            }
            Spacer(Modifier.height(30.dp))
            Text(
                text = "Kerja baik,\ndari dapur hingga tujuan.",
                style = MaterialTheme.typography.displaySmall,
            )
            Spacer(Modifier.height(12.dp))
            Text(
                text = "Masuk ke SPPG Mobile untuk melanjutkan pekerjaan hari ini.",
                style = MaterialTheme.typography.bodyLarge,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
            Spacer(Modifier.height(28.dp))
            Text(
                "MASUK KE AKUN",
                style = MaterialTheme.typography.labelMedium,
                fontWeight = FontWeight.Bold,
                color = MaterialTheme.colorScheme.primary,
            )
            Spacer(Modifier.height(10.dp))
            OutlinedTextField(
                value = login,
                onValueChange = {
                    login = it
                    if (errorMessage != null) onDismissError()
                },
                modifier = Modifier.fillMaxWidth(),
                label = { Text("Email atau nomor pegawai") },
                shape = RoundedCornerShape(16.dp),
                singleLine = true,
                keyboardOptions = KeyboardOptions(
                    keyboardType = KeyboardType.Email,
                    imeAction = ImeAction.Next,
                ),
            )
            Spacer(Modifier.height(16.dp))
            OutlinedTextField(
                value = password,
                onValueChange = {
                    password = it
                    if (errorMessage != null) onDismissError()
                },
                modifier = Modifier.fillMaxWidth(),
                label = { Text("Kata sandi") },
                shape = RoundedCornerShape(16.dp),
                singleLine = true,
                visualTransformation = if (passwordVisible) {
                    VisualTransformation.None
                } else {
                    PasswordVisualTransformation()
                },
                trailingIcon = {
                    IconButton(onClick = { passwordVisible = !passwordVisible }) {
                        Icon(
                            imageVector = if (passwordVisible) Icons.Outlined.VisibilityOff else Icons.Outlined.Visibility,
                            contentDescription = if (passwordVisible) "Sembunyikan kata sandi" else "Tampilkan kata sandi",
                        )
                    }
                },
                keyboardOptions = KeyboardOptions(
                    keyboardType = KeyboardType.Password,
                    imeAction = ImeAction.Done,
                ),
                keyboardActions = KeyboardActions(onDone = {
                    focusManager.clearFocus()
                    if (!isSubmitting) onLogin(login, password)
                }),
            )
            if (errorMessage != null) {
                Spacer(Modifier.height(12.dp))
                Text(
                    text = errorMessage,
                    color = MaterialTheme.colorScheme.error,
                    style = MaterialTheme.typography.bodyMedium,
                )
            }
            Spacer(Modifier.height(24.dp))
            Button(
                onClick = {
                    focusManager.clearFocus()
                    onLogin(login, password)
                },
                modifier = Modifier
                    .fillMaxWidth()
                    .height(52.dp),
                enabled = !isSubmitting,
                shape = RoundedCornerShape(16.dp),
            ) {
                if (isSubmitting) {
                    CircularProgressIndicator(
                        modifier = Modifier.size(22.dp),
                        color = MaterialTheme.colorScheme.onPrimary,
                        strokeWidth = 2.dp,
                    )
                } else {
                    Text("Masuk", fontWeight = FontWeight.SemiBold)
                }
            }
            Spacer(Modifier.height(24.dp))
            Text(
                text = "Gunakan akun yang sama dengan sistem SPPG V3.",
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
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

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun DashboardScreen(
    session: UserSession,
    isLoggingOut: Boolean,
    operationalState: OperationalUiState,
    noticeMessage: String?,
    onDismissNotice: () -> Unit,
    onLogout: () -> Unit,
    pendingTaskCount: Int,
    onOpenTasks: () -> Unit,
    onOpenFieldPlans: () -> Unit,
    onLoadOperationalModules: (Boolean) -> Unit,
    onOpenOperational: (String, String) -> Unit,
) {
    val isFieldAssistant = session.role == "asisten_lapangan"
    val features = buildList {
        if (isFieldAssistant) {
            add(FeatureItem("Rencana lapangan", "Konfirmasi penerima, rute, jadwal, dan aktivasi H-3.", "Siap digunakan", isAvailable = true))
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

    Scaffold(
        containerColor = MaterialTheme.colorScheme.background,
        topBar = {
            TopAppBar(
                title = {
                    Text("SPPG", fontWeight = FontWeight.ExtraBold)
                },
                actions = {
                    IconButton(onClick = onLogout, enabled = !isLoggingOut) {
                        Icon(Icons.AutoMirrored.Outlined.Logout, contentDescription = "Keluar")
                    }
                },
                colors = TopAppBarDefaults.topAppBarColors(
                    containerColor = MaterialTheme.colorScheme.background,
                ),
            )
        },
    ) { innerPadding ->
        LazyColumn(
            modifier = Modifier.fillMaxSize(),
            contentPadding = PaddingValues(
                start = 20.dp,
                top = innerPadding.calculateTopPadding() + 16.dp,
                end = 20.dp,
                bottom = 32.dp,
            ),
            verticalArrangement = Arrangement.spacedBy(14.dp),
        ) {
            item {
                DashboardHero(session)
                if (!noticeMessage.isNullOrBlank()) {
                    Spacer(Modifier.height(14.dp))
                    Card(
                        colors = CardDefaults.cardColors(
                            containerColor = MaterialTheme.colorScheme.secondaryContainer,
                        ),
                        shape = RoundedCornerShape(16.dp),
                    ) {
                        Column(Modifier.padding(16.dp)) {
                            Text(
                                "Koneksi belum diverifikasi",
                                fontWeight = FontWeight.Bold,
                                color = MaterialTheme.colorScheme.onSecondaryContainer,
                            )
                            Spacer(Modifier.height(5.dp))
                            Text(
                                noticeMessage,
                                style = MaterialTheme.typography.bodySmall,
                                color = MaterialTheme.colorScheme.onSecondaryContainer,
                            )
                            TextButton(onClick = onDismissNotice) { Text("Tutup") }
                        }
                    }
                }
                Spacer(Modifier.height(26.dp))
                Text(
                    text = "Ruang kerja Anda",
                    style = MaterialTheme.typography.titleLarge,
                    fontWeight = FontWeight.Bold,
                )
                Spacer(Modifier.height(5.dp))
                Text(
                    "Pilih modul untuk melihat pekerjaan hari ini.",
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                    style = MaterialTheme.typography.bodyMedium,
                )
            }

            item {
                FeatureCard(
                    feature = FeatureItem(
                        title = "Tugas Saya",
                        description = "Lihat pekerjaan yang jatuh tempo dan riwayat notifikasi.",
                        status = "$pendingTaskCount tugas aktif",
                        isAvailable = true,
                        visualSlug = "tasks",
                    ),
                    onClick = onOpenTasks,
                )
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
            } else if (features.isEmpty()) {
                item { UnsupportedRoleCard() }
            } else {
                items(features) { feature ->
                    FeatureCard(
                        feature = feature,
                        onClick = when {
                            !feature.isAvailable -> null
                            feature.operationalSlug != null -> {
                                { onOpenOperational(feature.operationalSlug, feature.operationalLabel.orEmpty()) }
                            }
                            else -> onOpenFieldPlans
                        },
                    )
                }
            }
        }
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
