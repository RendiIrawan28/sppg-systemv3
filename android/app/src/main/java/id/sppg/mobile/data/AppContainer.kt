package id.sppg.mobile.data

import android.content.Context
import id.sppg.mobile.BuildConfig
import id.sppg.mobile.core.notification.FirebaseTokenRegistrar
import id.sppg.mobile.data.remote.ApiErrorHandler
import id.sppg.mobile.data.remote.MobileApi
import id.sppg.mobile.data.session.SessionStore
import okhttp3.OkHttpClient
import retrofit2.Retrofit
import retrofit2.converter.gson.GsonConverterFactory
import java.util.concurrent.TimeUnit

class AppContainer(context: Context) {
    private val sessionStore = SessionStore(context.applicationContext)
    private val errorHandler = ApiErrorHandler(sessionStore)

    private val httpClient = OkHttpClient.Builder()
        .connectTimeout(20, TimeUnit.SECONDS)
        .readTimeout(45, TimeUnit.SECONDS)
        .writeTimeout(45, TimeUnit.SECONDS)
        .addInterceptor { chain ->
            val request = chain.request().newBuilder()
                .header("Accept", "application/json")
                .header("X-SPPG-Platform", "android")
                .header("X-SPPG-App-Version", BuildConfig.VERSION_NAME)
                .build()
            chain.proceed(request)
        }
        .build()

    private val api = Retrofit.Builder()
        .baseUrl(normalizeBaseUrl(BuildConfig.API_BASE_URL))
        .client(httpClient)
        .addConverterFactory(GsonConverterFactory.create())
        .build()
        .create(MobileApi::class.java)

    val authRepository = AuthRepository(api, sessionStore, errorHandler)
    val fieldPlanRepository = FieldPlanRepository(api, sessionStore, errorHandler)
    val operationalRepository = OperationalRepository(api, sessionStore, errorHandler)
    val notificationRepository = NotificationRepository(api, sessionStore, errorHandler)
    val securityRepository = SecurityRepository(api, sessionStore, errorHandler)
    val firebaseTokenRegistrar = FirebaseTokenRegistrar(context.applicationContext, notificationRepository)

    private fun normalizeBaseUrl(value: String): String =
        value.trim().let { if (it.endsWith('/')) it else "$it/" }
}
