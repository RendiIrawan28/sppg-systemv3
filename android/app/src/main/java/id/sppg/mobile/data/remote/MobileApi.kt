package id.sppg.mobile.data.remote

import com.google.gson.annotations.SerializedName
import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.Header
import retrofit2.http.POST
import retrofit2.http.Path
import retrofit2.http.PUT
import retrofit2.http.Query

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
        @Query("per_page") perPage: Int = 50,
    ): Response<PaginatedFieldPlans>

    @GET("field-plans/{id}")
    suspend fun fieldPlan(
        @Header("Authorization") authorization: String,
        @Path("id") id: Long,
    ): Response<FieldPlanResponse>

    @PUT("field-plans/{id}")
    suspend fun updateFieldPlan(
        @Header("Authorization") authorization: String,
        @Path("id") id: Long,
        @Body request: UpdateFieldPlanRequest,
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

    @GET("operational-modules")
    suspend fun operationalModules(
        @Header("Authorization") authorization: String,
    ): Response<OperationalModulesResponse>

    @GET("operational-modules/{module}/records")
    suspend fun operationalRecords(
        @Header("Authorization") authorization: String,
        @Path("module") module: String,
        @Query("per_page") perPage: Int = 50,
    ): Response<OperationalRecordsResponse>

    @GET("operational-modules/{module}/records/{id}")
    suspend fun operationalRecord(
        @Header("Authorization") authorization: String,
        @Path("module") module: String,
        @Path("id") id: Long,
    ): Response<OperationalRecordResponse>
}

data class LoginRequest(
    val login: String,
    val password: String,
    @SerializedName("device_name") val deviceName: String,
)

data class LoginResponse(
    @SerializedName("access_token") val accessToken: String,
    @SerializedName("token_type") val tokenType: String,
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
data class ApiError(val message: String?)

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
    @SerializedName("can_activate") val canActivate: Boolean,
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
    @SerializedName("planned_departure_time") val plannedDepartureTime: String?,
    @SerializedName("planned_arrival_time") val plannedArrivalTime: String?,
    @SerializedName("special_notes") val specialNotes: String?,
    @SerializedName("change_reason") val changeReason: String?,
    @SerializedName("recipient_groups") val recipientGroups: List<UpdateRecipientGroupRequest>,
)

data class UpdateRecipientGroupRequest(
    val id: Long,
    @SerializedName("confirmed_beneficiaries") val confirmedBeneficiaries: Int,
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

data class OperationalModulesResponse(val data: List<OperationalModule>)

data class OperationalModule(
    val slug: String,
    val label: String,
    val description: String,
    val permission: String,
    @SerializedName("record_count") val recordCount: Int,
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
    val assignee: String?,
    val metrics: List<OperationalMetric>,
    val fields: List<OperationalField>?,
    val sections: List<OperationalSection>?,
)

data class OperationalMetric(
    val label: String,
    val value: String,
)

data class OperationalField(
    val key: String,
    val label: String,
    val value: String,
)

data class OperationalSection(
    val key: String,
    val title: String,
    val items: List<OperationalSectionItem>,
)

data class OperationalSectionItem(
    val id: Long,
    val title: String,
    val fields: List<OperationalField>,
)
