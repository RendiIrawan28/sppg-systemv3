package id.sppg.mobile

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.lifecycle.viewmodel.compose.viewModel
import id.sppg.mobile.ui.AuthViewModel
import id.sppg.mobile.ui.FieldPlanViewModel
import id.sppg.mobile.ui.OperationalViewModel
import id.sppg.mobile.ui.SppgApp

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()
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
            SppgApp(authViewModel, fieldPlanViewModel, operationalViewModel)
        }
    }
}
