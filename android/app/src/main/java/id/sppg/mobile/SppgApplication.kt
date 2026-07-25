package id.sppg.mobile

import android.app.Application
import id.sppg.mobile.data.AppContainer

class SppgApplication : Application() {
    lateinit var container: AppContainer
        private set

    override fun onCreate() {
        super.onCreate()
        container = AppContainer(this)
    }
}

