package id.sppg.mobile

import android.content.Intent
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.lifecycle.viewmodel.compose.viewModel
import id.sppg.mobile.core.notification.NotificationNavigationStore
import id.sppg.mobile.ui.AuthViewModel
import id.sppg.mobile.ui.FieldPlanViewModel
import id.sppg.mobile.ui.NotificationViewModel
import id.sppg.mobile.ui.OperationalViewModel
import id.sppg.mobile.ui.SecurityViewModel
import id.sppg.mobile.ui.SppgApp

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()
        NotificationNavigationStore.publish(intent)
        val container = (application as SppgApplication).container

        setContent {
            val authViewModel: AuthViewModel = viewModel(
                factory = AuthViewModel.factory(container.authRepository),
            )
            val fieldPlanViewModel: FieldPlanViewModel = viewModel(
                factory = FieldPlanViewModel.factory(container.fieldPlanRepository),
            )
            val operationalViewModel: OperationalViewModel = viewModel(
                factory = OperationalViewModel.factory(container.operationalRepository),
            )
            val notificationViewModel: NotificationViewModel = viewModel(
                factory = NotificationViewModel.factory(
                    container.notificationRepository,
                    container.firebaseTokenRegistrar,
                ),
            )
            val securityViewModel: SecurityViewModel = viewModel(
                factory = SecurityViewModel.factory(container.securityRepository),
            )
            SppgApp(
                authViewModel = authViewModel,
                fieldPlanViewModel = fieldPlanViewModel,
                operationalViewModel = operationalViewModel,
                notificationViewModel = notificationViewModel,
                securityViewModel = securityViewModel,
            )
        }
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        setIntent(intent)
        NotificationNavigationStore.publish(intent)
    }
}
