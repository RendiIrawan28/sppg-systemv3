package id.sppg.mobile.data.remote

import com.google.gson.annotations.SerializedName
import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.DELETE
import retrofit2.http.GET
import retrofit2.http.Header
import retrofit2.http.POST
import retrofit2.http.Path
import retrofit2.http.PUT
import retrofit2.http.Query
import retrofit2.http.Streaming
import okhttp3.ResponseBody

interface MobileApi {
    @POST("login")
    suspend fun login(@Body request: LoginRequest): Response<LoginResponse>

    @GET("user")
    suspend fun user(@Header("Authorization") authorization: String): Response<UserResponse>

    @POST("logout")
    suspend fun logout(@Header("Authorization") authorization: String): Response<MessageResponse>

    @GET("field-plans")
    suspend fun fieldPlans(
        @Header("Authorization") authorization: String,
        @Query("date_from") dateFrom: String? = null,
        @Query("date_to") dateTo: String? = null,
        @Query("scope") scope: String? = null,
        @Query("per_page") perPage: Int = 50,
        @Query("page") page: Int = 1,
    ): Response<PaginatedFieldPlans>

    @GET("field-plans/{id}")
    suspend fun fieldPlan(
        @Header("Authorization") authorization: String,
        @Path("id") id: Long,
    ): Response<FieldPlanResponse>

    @GET("field-plans/options")
    suspend fun fieldPlanOptions(
        @Header("Authorization") authorization: String,
    ): Response<FieldPlanOptionsResponse>

    @POST("field-plans")
    suspend fun createFieldPlan(
        @Header("Authorization") authorization: String,
        @Body request: CreateFieldPlanRequest,
    ): Response<FieldPlanResponse>

    @PUT("field-plans/{id}")
    suspend fun updateFieldPlan(
        @Header("Authorization") authorization: String,
        @Path("id") id: Long,
        @Body request: UpdateFieldPlanRequest,
    ): Response<FieldPlanResponse>

    @PUT("field-plans/{id}/routes")
    suspend fun reviseFieldPlanRoutes(
        @Header("Authorization") authorization: String,
        @Path("id") id: Long,
        @Body request: ReviseFieldPlanRoutesRequest,
    ): Response<FieldPlanResponse>

    @DELETE("field-plans/{id}")
    suspend fun deleteFieldPlan(
        @Header("Authorization") authorization: String,
        @Path("id") id: Long,
    ): Response<MessageResponse>

    @POST("field-plans/{id}/refresh-beneficiaries")
    suspend fun refreshFieldPlanBeneficiaries(
        @Header("Authorization") authorization: String,
        @Path("id") id: Long,
    ): Response<FieldPlanResponse>

    @GET("field-plans/{id}/readiness")
    suspend fun fieldPlanReadiness(
        @Header("Authorization") authorization: String,
        @Path("id") id: Long,
    ): Response<ReadinessResponse>

    @POST("field-plans/{id}/activate")
    suspend fun activateFieldPlan(
        @Header("Authorization") authorization: String,
        @Path("id") id: Long,
        @Body request: ActivateFieldPlanRequest,
    ): Response<ActivateFieldPlanResponse>

    @Streaming
    @GET("field-plans/{id}/document")
    suspend fun fieldPlanDocument(
        @Header("Authorization") authorization: String,
        @Path("id") id: Long,
        @Query("format") format: String = "pdf",
    ): Response<ResponseBody>

    @GET("operational-modules")
    suspend fun operationalModules(
        @Header("Authorization") authorization: String,
    ): Response<OperationalModulesResponse>

    @GET("operational-modules/{module}/records")
    suspend fun operationalRecords(
        @Header("Authorization") authorization: String,
        @Path("module") module: String,
        @Query("search") search: String? = null,
        @Query("status") status: String? = null,
        @Query("date_from") dateFrom: String? = null,
        @Query("date_to") dateTo: String? = null,
        @Query("view") view: String? = null,
        @Query("per_page") perPage: Int = 50,
        @Query("page") page: Int = 1,
    ): Response<OperationalRecordsResponse>

    @GET("operational-modules/{module}/records/{id}")
    suspend fun operationalRecord(
        @Header("Authorization") authorization: String,
        @Path("module") module: String,
        @Path("id") id: Long,
    ): Response<OperationalRecordResponse>

    @POST("operational-modules/{module}/records")
    suspend fun createOperationalRecord(
        @Header("Authorization") authorization: String,
        @Path("module") module: String,
        @Body request: OperationalSaveRequest,
    ): Response<OperationalRecordResponse>

    @PUT("operational-modules/{module}/records/{id}")
    suspend fun updateOperationalRecord(
        @Header("Authorization") authorization: String,
        @Path("module") module: String,
        @Path("id") id: Long,
        @Body request: OperationalSaveRequest,
    ): Response<OperationalRecordResponse>

    @DELETE("operational-modules/{module}/records/{id}")
    suspend fun deleteOperationalRecord(
        @Header("Authorization") authorization: String,
        @Path("module") module: String,
        @Path("id") id: Long,
    ): Response<MessageResponse>

    @POST("operational-modules/{module}/records/{id}/actions/{action}")
    suspend fun runOperationalAction(
        @Header("Authorization") authorization: String,
        @Path("module") module: String,
        @Path("id") id: Long,
        @Path("action") action: String,
        @Body request: OperationalActionRequest,
    ): Response<OperationalRecordResponse>

    @POST("operational-modules/{module}/records/{id}/relations/{relation}")
    suspend fun createOperationalRelation(
        @Header("Authorization") authorization: String,
        @Path("module") module: String,
        @Path("id") id: Long,
        @Path("relation") relation: String,
        @Body request: OperationalRelationSaveRequest,
    ): Response<OperationalRelationItemResponse>

    @PUT("operational-modules/{module}/records/{id}/relations/{relation}/{item}")
    suspend fun updateOperationalRelation(
        @Header("Authorization") authorization: String,
        @Path("module") module: String,
        @Path("id") id: Long,
        @Path("relation") relation: String,
        @Path("item") item: Long,
        @Body request: OperationalRelationSaveRequest,
    ): Response<OperationalRelationItemResponse>

    @DELETE("operational-modules/{module}/records/{id}/relations/{relation}/{item}")
    suspend fun deleteOperationalRelation(
        @Header("Authorization") authorization: String,
        @Path("module") module: String,
        @Path("id") id: Long,
        @Path("relation") relation: String,
        @Path("item") item: Long,
    ): Response<MessageResponse>

    @Streaming
    @GET("operational-modules/{module}/records/{id}/document")
    suspend fun operationalDocument(
        @Header("Authorization") authorization: String,
        @Path("module") module: String,
        @Path("id") id: Long,
        @Query("type") type: String? = null,
    ): Response<ResponseBody>

    @POST("operational-modules/{module}/records/{id}/relations/{relation}/{item}/actions/{action}")
    suspend fun runOperationalRelationAction(
        @Header("Authorization") authorization: String,
        @Path("module") module: String,
        @Path("id") id: Long,
        @Path("relation") relation: String,
        @Path("item") item: Long,
        @Path("action") action: String,
        @Body request: OperationalActionRequest,
    ): Response<MessageResponse>


    @POST("device-tokens")
    suspend fun registerDeviceToken(
        @Header("Authorization") authorization: String,
        @Body request: RegisterDeviceTokenRequest,
    ): Response<DeviceTokenResponse>

    @DELETE("device-tokens/{installationId}")
    suspend fun unregisterDeviceToken(
        @Header("Authorization") authorization: String,
        @Path("installationId") installationId: String,
    ): Response<MessageResponse>

    @GET("tasks")
    suspend fun tasks(
        @Header("Authorization") authorization: String,
        @Query("status") status: String = "pending",
        @Query("per_page") perPage: Int = 50,
    ): Response<MobileTasksResponse>

    @GET("notifications")
    suspend fun notifications(
        @Header("Authorization") authorization: String,
    ): Response<MobileNotificationsResponse>

    @GET("notifications/status")
    suspend fun notificationStatus(
        @Header("Authorization") authorization: String,
        @Query("installation_id") installationId: String,
    ): Response<PushNotificationStatusResponse>

    @POST("notifications/test")
    suspend fun sendTestNotification(
        @Header("Authorization") authorization: String,
        @Body request: TestNotificationRequest,
    ): Response<TestNotificationResponse>

    @POST("notifications/{id}/read")
    suspend fun readNotification(
        @Header("Authorization") authorization: String,
        @Path("id") id: Long,
    ): Response<MessageResponse>

    @POST("notifications/read-all")
    suspend fun readAllNotifications(
        @Header("Authorization") authorization: String,
    ): Response<MessageResponse>

    @GET("security/overview")
    suspend fun securityOverview(
        @Header("Authorization") authorization: String,
        @Query("date") date: String? = null,
    ): Response<SecurityOverviewResponse>

    @POST("security/shifts")
    suspend fun startSecurityShift(
        @Header("Authorization") authorization: String,
    ): Response<SecurityShiftResponse>

    @POST("security/shifts/{id}/reports")
    suspend fun submitSecurityReport(
        @Header("Authorization") authorization: String,
        @Path("id") id: Long,
        @Body request: SubmitSecurityReportRequest,
    ): Response<SecurityShiftResponse>
}

data class LoginRequest(
    val login: String,
    val password: String,
    @SerializedName("device_name") val deviceName: String,
    @SerializedName("device_id") val deviceId: String,
)

data class LoginResponse(
    @SerializedName("access_token") val accessToken: String,
    @SerializedName("token_type") val tokenType: String,
    @SerializedName("expires_at") val expiresAt: String?,
    val user: MobileUser,
)

data class UserResponse(val user: MobileUser)

data class MobileUser(
    val id: Long,
    @SerializedName("employee_number") val employeeNumber: String?,
    val name: String,
    val email: String,
    val phone: String?,
    @SerializedName("primary_role") val primaryRole: String?,
    @SerializedName("primary_role_label") val primaryRoleLabel: String,
    val roles: List<String>,
    val permissions: List<String>,
    val unit: MobileUnit?,
)

data class MobileUnit(
    val id: Long,
    val code: String,
    val name: String,
    val address: String?,
)

data class MessageResponse(val message: String)
data class ApiError(
    val message: String?,
    val errors: Map<String, List<String>>?,
)

data class PaginatedFieldPlans(
    val data: List<FieldPlan>,
    val meta: PaginationMeta?,
)

data class PaginationMeta(
    @SerializedName("current_page") val currentPage: Int,
    @SerializedName("last_page") val lastPage: Int,
    val total: Int,
)

data class FieldPlanResponse(val data: FieldPlan)

data class FieldPlanOptionsResponse(
    val data: List<FieldPlanOption>,
    @SerializedName("can_create") val canCreate: Boolean,
)

data class FieldPlanOption(
    val id: Long,
    @SerializedName("cycle_code") val cycleCode: String?,
    @SerializedName("cycle_name") val cycleName: String?,
    @SerializedName("day_number") val dayNumber: Int,
    @SerializedName("label_code") val labelCode: String?,
    @SerializedName("menu_name") val menuName: String,
    @SerializedName("distribution_date") val distributionDate: String,
    @SerializedName("service_date") val serviceDate: String?,
    @SerializedName("production_date") val productionDate: String?,
    @SerializedName("is_rapel") val isRapel: Boolean,
    @SerializedName("has_plan") val hasPlan: Boolean,
    @SerializedName("is_available") val isAvailable: Boolean,
    @SerializedName("unavailable_reason") val unavailableReason: String?,
)

data class CreateFieldPlanRequest(
    @SerializedName("distribution_date") val distributionDate: String,
    @SerializedName("menu_cycle_day_id") val menuCycleDayId: Long? = null,
    @SerializedName("confirmation_deadline_at") val confirmationDeadlineAt: String? = null,
    @SerializedName("general_notes") val generalNotes: String? = null,
)

data class FieldPlan(
    val id: Long,
    val uuid: String,
    @SerializedName("plan_number") val planNumber: String,
    @SerializedName("distribution_date") val distributionDate: String,
    @SerializedName("service_date") val serviceDate: String?,
    @SerializedName("production_date") val productionDate: String?,
    @SerializedName("menu_name") val menuName: String?,
    val shift: String?,
    @SerializedName("is_rapel") val isRapel: Boolean,
    val status: String,
    @SerializedName("status_label") val statusLabel: String,
    @SerializedName("planned_beneficiaries") val plannedBeneficiaries: Int,
    @SerializedName("confirmed_beneficiaries") val confirmedBeneficiaries: Int,
    @SerializedName("small_portions") val smallPortions: Int,
    @SerializedName("large_portions") val largePortions: Int,
    @SerializedName("total_portions") val totalPortions: Int,
    @SerializedName("destination_count") val destinationCount: Int,
    @SerializedName("confirmation_deadline_at") val confirmationDeadlineAt: String?,
    @SerializedName("general_notes") val generalNotes: String?,
    @SerializedName("is_editable") val isEditable: Boolean,
    @SerializedName("can_update") val canUpdate: Boolean,
    @SerializedName("can_revise_routes") val canReviseRoutes: Boolean = false,
    @SerializedName("can_delete") val canDelete: Boolean = false,
    @SerializedName("can_refresh") val canRefresh: Boolean = false,
    @SerializedName("can_activate") val canActivate: Boolean,
    @SerializedName("can_export") val canExport: Boolean = false,
    val destinations: List<FieldPlanDestination>?,
)

data class FieldPlanDestination(
    val id: Long,
    val type: String?,
    val code: String?,
    val name: String,
    val address: String?,
    @SerializedName("contact_name") val contactName: String?,
    @SerializedName("contact_phone") val contactPhone: String?,
    @SerializedName("route_name") val routeName: String?,
    @SerializedName("sequence_order") val sequenceOrder: Int,
    @SerializedName("registered_beneficiaries") val registeredBeneficiaries: Int,
    @SerializedName("confirmed_beneficiaries") val confirmedBeneficiaries: Int,
    @SerializedName("small_portions") val smallPortions: Int,
    @SerializedName("large_portions") val largePortions: Int,
    @SerializedName("total_portions") val totalPortions: Int,
    @SerializedName("planned_departure_time") val plannedDepartureTime: String?,
    @SerializedName("planned_arrival_time") val plannedArrivalTime: String?,
    @SerializedName("confirmation_status") val confirmationStatus: String?,
    @SerializedName("confirmed_at") val confirmedAt: String?,
    @SerializedName("change_reason") val changeReason: String?,
    @SerializedName("special_notes") val specialNotes: String?,
    @SerializedName("recipient_groups") val recipientGroups: List<FieldPlanRecipientGroup>,
)

data class FieldPlanRecipientGroup(
    val id: Long,
    @SerializedName("category_code") val categoryCode: String?,
    @SerializedName("category_name") val categoryName: String,
    @SerializedName("menu_audience") val menuAudience: String?,
    @SerializedName("portion_size") val portionSize: String?,
    @SerializedName("registered_beneficiaries") val registeredBeneficiaries: Int,
    @SerializedName("confirmed_beneficiaries") val confirmedBeneficiaries: Int,
    @SerializedName("small_portions") val smallPortions: Int,
    @SerializedName("large_portions") val largePortions: Int,
    @SerializedName("total_portions") val totalPortions: Int,
    val notes: String?,
)

data class UpdateFieldPlanRequest(
    @SerializedName("general_notes") val generalNotes: String?,
    val destinations: List<UpdateFieldPlanDestinationRequest>,
)

data class UpdateFieldPlanDestinationRequest(
    val id: Long,
    @SerializedName("route_name") val routeName: String?,
    @SerializedName("sequence_order") val sequenceOrder: Int,
    @SerializedName("special_notes") val specialNotes: String?,
    @SerializedName("change_reason") val changeReason: String?,
    @SerializedName("no_service_reason") val noServiceReason: String?,
    @SerializedName("recipient_groups") val recipientGroups: List<UpdateRecipientGroupRequest>,
)

data class ReviseFieldPlanRoutesRequest(
    val destinations: List<ReviseFieldPlanRouteRequest>,
)

data class ReviseFieldPlanRouteRequest(
    val id: Long,
    @SerializedName("route_name") val routeName: String?,
    @SerializedName("sequence_order") val sequenceOrder: Int,
)

data class UpdateRecipientGroupRequest(
    val id: Long,
    @SerializedName("confirmed_beneficiaries") val confirmedBeneficiaries: Int,
    @SerializedName("menu_audience") val menuAudience: String,
    @SerializedName("portion_size") val portionSize: String,
    val notes: String?,
)

data class ReadinessResponse(
    val ready: Boolean,
    val message: String,
    val issues: List<String>,
)

data class ActivateFieldPlanRequest(val notes: String?)

data class ActivateFieldPlanResponse(
    val message: String,
    val data: FieldPlan,
)

data class OperationalModulesResponse(
    val data: List<OperationalModule>,
    @SerializedName("daily_summary") val dailySummary: MobileDailySummary? = null,
)

data class MobileDailySummary(
    val date: String,
    @SerializedName("menu_names") val menuNames: List<String> = emptyList(),
    val beneficiaries: Int = 0,
    val portions: Int = 0,
    val destinations: Int = 0,
)

data class OperationalModule(
    val slug: String,
    val label: String,
    val description: String,
    val permission: String,
    @SerializedName("record_count") val recordCount: Int,
    @SerializedName("today_count") val todayCount: Int = 0,
    @SerializedName("can_create") val canCreate: Boolean,
    @SerializedName("form_fields") val formFields: List<OperationalFormField>?,
)

data class OperationalRecordsResponse(
    val data: List<OperationalRecord>,
    val meta: PaginationMeta?,
)

data class OperationalRecordResponse(val data: OperationalRecord)

data class OperationalRecord(
    val id: Long,
    val number: String,
    val date: String?,
    val title: String,
    val subtitle: String?,
    val state: String?,
    @SerializedName("state_label") val stateLabel: String?,
    val status: String?,
    @SerializedName("status_label") val statusLabel: String?,
    @SerializedName("is_history") val isHistory: Boolean = false,
    val assignee: String?,
    val metrics: List<OperationalMetric>,
    val fields: List<OperationalField>?,
    val sections: List<OperationalSection>?,
    @SerializedName("form_fields") val formFields: List<OperationalFormField>?,
    val capabilities: OperationalCapabilities?,
)

data class OperationalFormField(
    val key: String,
    val label: String,
    val type: String,
    val value: String?,
    val required: Boolean,
    val editable: Boolean,
    val options: Map<String, String>?,
)

data class OperationalCapabilities(
    @SerializedName("can_update") val canUpdate: Boolean,
    @SerializedName("can_delete") val canDelete: Boolean,
    @SerializedName("can_view_document") val canViewDocument: Boolean = false,
    val actions: List<OperationalAction>?,
)

data class OperationalSaveRequest(
    val fields: Map<String, String?>,
    val files: Map<String, String> = emptyMap(),
)
data class OperationalActionRequest(
    val notes: String?,
    val fields: Map<String, String?> = emptyMap(),
    val files: Map<String, String> = emptyMap(),
)
data class OperationalRelationSaveRequest(
    val fields: Map<String, String?>,
    val files: Map<String, String>,
)
data class OperationalRelationItemResponse(val data: OperationalRelationItem)
data class OperationalRelationItem(
    val id: Long,
    @SerializedName("form_fields") val formFields: List<OperationalFormField>,
)

data class OperationalAction(
    val key: String,
    val label: String,
    @SerializedName("notes_required") val notesRequired: Boolean,
    val fields: List<OperationalFormField>? = emptyList(),
)

data class OperationalMetric(
    val label: String,
    val value: String,
)

data class OperationalField(
    val key: String,
    val label: String,
    val value: String,
    val type: String? = null,
    @SerializedName("file_url") val fileUrl: String? = null,
)

data class OperationalSection(
    val key: String,
    val title: String,
    val items: List<OperationalSectionItem>,
    @SerializedName("can_create") val canCreate: Boolean,
    @SerializedName("empty_form_fields") val emptyFormFields: List<OperationalFormField>?,
)

data class OperationalSectionItem(
    val id: Long,
    val title: String,
    val fields: List<OperationalField>,
    @SerializedName("form_fields") val formFields: List<OperationalFormField>?,
    @SerializedName("can_update") val canUpdate: Boolean,
    @SerializedName("can_delete") val canDelete: Boolean,
    val actions: List<OperationalRelationAction>?,
)

data class OperationalRelationAction(
    val key: String,
    val label: String,
    @SerializedName("notes_required") val notesRequired: Boolean = false,
    val fields: List<OperationalFormField>? = emptyList(),
)


data class RegisterDeviceTokenRequest(
    @SerializedName("fcm_token") val fcmToken: String,
    @SerializedName("installation_id") val installationId: String,
    @SerializedName("device_name") val deviceName: String,
    @SerializedName("app_version") val appVersion: String,
    val platform: String = "android",
)

data class DeviceTokenResponse(
    val message: String,
    val data: DeviceTokenData,
)

data class DeviceTokenData(
    val id: Long,
    val registered: Boolean,
)

data class MobileTasksResponse(
    val data: List<MobileTaskItem>,
    val meta: MobileTaskMeta,
)

data class MobileTaskMeta(
    @SerializedName("current_page") val currentPage: Int,
    @SerializedName("last_page") val lastPage: Int,
    val total: Int,
    @SerializedName("pending_count") val pendingCount: Int,
    @SerializedName("unread_notification_count") val unreadNotificationCount: Int,
)

data class MobileTaskItem(
    val id: Long,
    val uuid: String,
    val type: String,
    val title: String,
    val description: String?,
    val priority: String,
    val status: String,
    val screen: String?,
    val payload: Map<String, String>?,
    @SerializedName("due_at") val dueAt: String?,
    @SerializedName("is_overdue") val isOverdue: Boolean,
    @SerializedName("completed_at") val completedAt: String?,
)

data class MobileNotificationsResponse(
    val data: List<MobileNotificationItem>,
    val meta: MobileNotificationMeta,
)

data class MobileNotificationMeta(
    @SerializedName("unread_count") val unreadCount: Int,
)

data class MobileNotificationItem(
    val id: Long,
    val title: String,
    val body: String,
    val type: String,
    val channel: String,
    val screen: String?,
    val payload: Map<String, String>?,
    @SerializedName("delivery_status") val deliveryStatus: String,
    @SerializedName("error_message") val errorMessage: String?,
    @SerializedName("created_at") val createdAt: String?,
    @SerializedName("read_at") val readAt: String?,
)

data class TestNotificationRequest(
    @SerializedName("installation_id") val installationId: String,
)

data class PushNotificationStatusResponse(val data: PushNotificationStatus)

data class PushNotificationStatus(
    @SerializedName("firebase_configured") val firebaseConfigured: Boolean,
    @SerializedName("firebase_message") val firebaseMessage: String,
    @SerializedName("device_registered") val deviceRegistered: Boolean,
    @SerializedName("device_active") val deviceActive: Boolean,
    @SerializedName("device_name") val deviceName: String?,
    @SerializedName("app_version") val appVersion: String?,
    @SerializedName("registered_at") val registeredAt: String?,
    @SerializedName("last_seen_at") val lastSeenAt: String?,
    @SerializedName("server_time") val serverTime: String?,
)

data class TestNotificationResponse(
    val message: String,
    val data: TestNotificationResult,
)

data class TestNotificationResult(
    @SerializedName("notification_id") val notificationId: Long,
    @SerializedName("delivery_status") val deliveryStatus: String,
    @SerializedName("error_message") val errorMessage: String?,
    @SerializedName("sent_at") val sentAt: String?,
)

data class SecurityOverviewResponse(val data: SecurityOverview)

data class SecurityOverview(
    @SerializedName("active_shift") val activeShift: SecurityShiftData?,
    @SerializedName("recent_shifts") val recentShifts: List<SecurityShiftSummary>,
    @SerializedName("pending_tasks") val pendingTasks: List<SecurityTaskSummary>,
    @SerializedName("can_start_shift") val canStartShift: Boolean,
)

data class SecurityShiftResponse(
    val message: String,
    val data: SecurityShiftData,
)

data class SecurityShiftData(
    val id: Long,
    val uuid: String,
    @SerializedName("officer_name") val officerName: String,
    @SerializedName("started_at") val startedAt: String?,
    @SerializedName("scheduled_end_at") val scheduledEndAt: String?,
    @SerializedName("completed_at") val completedAt: String?,
    val status: String,
    @SerializedName("reports_expected") val reportsExpected: Int,
    @SerializedName("reports_count") val reportsCount: Int,
    @SerializedName("next_report_sequence") val nextReportSequence: Int?,
    @SerializedName("next_report_due_at") val nextReportDueAt: String?,
    @SerializedName("report_due") val reportDue: Boolean,
    val reports: List<SecurityReportItem>,
)

data class SecurityShiftSummary(
    val id: Long,
    @SerializedName("started_at") val startedAt: String?,
    @SerializedName("completed_at") val completedAt: String?,
    val status: String,
    @SerializedName("reports_count") val reportsCount: Int,
    @SerializedName("reports_expected") val reportsExpected: Int,
    val reports: List<SecurityReportItem> = emptyList(),
)

data class SecurityTaskSummary(
    val id: Long,
    @SerializedName("sequence_number") val sequenceNumber: Int?,
    val title: String,
    @SerializedName("due_at") val dueAt: String?,
    @SerializedName("is_overdue") val isOverdue: Boolean,
)

data class SecurityReportItem(
    val id: Long,
    @SerializedName("sequence_number") val sequenceNumber: Int,
    @SerializedName("due_at") val dueAt: String?,
    @SerializedName("reported_at") val reportedAt: String?,
    val situation: String,
    @SerializedName("gate_secure") val gateSecure: Boolean,
    @SerializedName("perimeter_secure") val perimeterSecure: Boolean,
    @SerializedName("access_activity") val accessActivity: String?,
    @SerializedName("visitor_activity") val visitorActivity: String?,
    val notes: String?,
    @SerializedName("photo_url") val photoUrl: String?,
)

data class SubmitSecurityReportRequest(
    val situation: String,
    @SerializedName("gate_secure") val gateSecure: Boolean,
    @SerializedName("perimeter_secure") val perimeterSecure: Boolean,
    @SerializedName("access_activity") val accessActivity: String?,
    @SerializedName("visitor_activity") val visitorActivity: String?,
    val notes: String?,
    val photo: String,
)
