package id.sppg.mobile.data

import android.content.Context
import id.sppg.mobile.R
import id.sppg.mobile.data.remote.MobileApi
import id.sppg.mobile.data.session.SessionStore
import retrofit2.Retrofit
import retrofit2.converter.gson.GsonConverterFactory

class AppContainer(context: Context) {
    private val sessionStore = SessionStore(context)
    private val api = Retrofit.Builder()
        .baseUrl(context.getString(R.string.api_base_url))
        .addConverterFactory(GsonConverterFactory.create())
        .build()
        .create(MobileApi::class.java)

    val authRepository = AuthRepository(api, sessionStore)
    val fieldPlanRepository = FieldPlanRepository(api, sessionStore)
    val operationalRepository = OperationalRepository(api, sessionStore)
}
