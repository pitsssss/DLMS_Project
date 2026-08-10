# مخططات التسلسل لنظام سيرتك — SYRTAK

## 1. مقدمة

يمثل هذا المستند الحزمة التوثيقية النهائية لمخططات التسلسل (UML Sequence Diagrams) لنظام **سيرتك / DLMS** وفق السلوك **المنفَّذ حالياً** في المستودع.

### ماذا تمثّل مخططات التسلسل؟
توضح التفاعل الزمني بين الجهات والأنظمة: من يستدعي من، وبأي ترتيب، وعبر أي طبقة برمجية، وماذا يُحفظ، وما المسارات البديلة، وما الإشعارات أو الاستدعاءات الخارجية الناتجة.

### النطاق
- مصادقة المواطن والملف الشخصي ومراجعته
- إنشاء الطلب والوثائق والدفع والمواعيد والاختبارات والإصدار
- التجديد وبدل الفاقد/التالف
- المسار اليدوي ومسار الوكيل الذكي (مع التأكيد)
- مركز الإشعارات وتسجيل أجهزة Push وتسليم Firebase
- عمليات الموظفين والصلاحيات وجلسات لوحة التحكم

### مصدر الحقيقة (حسب الأولوية)
1. التنفيذ التنفيذي الحالي  
2. الاختبارات الآلية  
3. Enums وقواعد الحالة  
4. عقود الـ API  
5. الوثائق الحالية  
6. المخططات/الوثائق التاريخية  

الملف الرسومي: `SYRTAK_COMPLETE_SEQUENCE_DIAGRAMS.drawio`  
مرجع اتساق المصطلحات مع: `SYRTAK_COMPLETE_ACTIVITY_DIAGRAMS.drawio` و`SYRTAK_COMPLETE_ERD.drawio` (مع بقاء الكود مصدر الحقيقة).

---

## 2. منهجية البناء

1. تدقيق المسارات في `routes/api.php` وملفات Routes للوحدات (Dashboard / Admin / AIAgent / Settings / Content) و`web.php`.
2. تتبع السلسلة: Route → Controller → Service → Repository/Model → أثر جانبي (إشعار/Queue/بوابة).
3. تدقيق انتقالات `ApplicationRepository::transitionStatus` والخدمات المستدعية.
4. تدقيق مسار Push: `NotificationService` → `planPushSafely` → `PushDeliveryService::planForNotification` → `SendPushNotificationJob` → `FcmClient`.
5. تدقيق الوكيل: `AIAgentService`, `AIAgentActionService`, `AgentActionExecutor`, `AgentSafetyRules`, `AgentWorkflowActionMap`.
6. مطابقة الاختبارات Feature ذات الصلة.
7. مقارنة المصطلحات مع مخططات النشاط وERD دون نسخ سلوك قديم مخالف للكود.

---

## 3. دليل الرموز

| الرمز | المعنى |
|------|--------|
| Actor | جهة بشرية (مواطن / موظف) |
| Participant | نظام/طبقة (Flutter، Controller، Service، DB، FCM…) |
| Lifeline | خط حياة المشارك عبر الزمن |
| Activation | شريط تفعيل أثناء تنفيذ العملية |
| Synchronous Message | استدعاء متزامن (سهم ممتلئ) |
| Asynchronous Message | إرسال غير متزامن مثل `dispatch` للـ Queue |
| Return Message | رسالة عودة متقطعة عند الحاجة |
| `alt` | مسارات بديلة حصرية |
| `opt` | سلوك اختياري |
| `loop` | تكرار (مثل لكل جهاز Push) |
| `par` | توازٍ حقيقي (نادر الاستخدام هنا) |
| Guard `[...]` | شرط المسار |

---

## 4. طبقات النظام في المخططات

| الطبقة | أمثلة | الدور في التسلسل |
|--------|-------|------------------|
| إنسان | Citizen / Employee / Reviewer | يطلق النية |
| عميل | Flutter App / Dashboard / Chat UI | يرسل HTTP |
| API/Controller | `ApplicationController`, `AIAgentController`… | نقطة دخول |
| Domain Service | `ApplicationService`, `PaymentLifecycleService`… | منطق الأعمال |
| Persistence | Repository / Database | الحفظ والاستعلام |
| خارجي | Stripe / Firebase FCM / GeminiClient | تكامل |
| Async | Queue / `SendPushNotificationJob` | تسليم لاحق غير حاجب |

تجنّب المخططات إدراج Middleware/FormRequest/DTO كـ lifelines منفصلة إلا عند الضرورة؛ التفاصيل الأمنية تُشرح نصياً.

---

## 5. شرح كل Sequence Diagram

### 01 - نظرة شاملة | End-to-End Overview

**الهدف**  
عرض رحلة الترخيص الشاملة بمستوى تقديمي دون طبقات داخلية.

**السيناريو**  
من بدء الطلب حتى الإصدار والإشعار.

**المشاركون**  
Citizen، Flutter، DLMS Backend، Employee/Dashboard، External Services.

**نقطة البداية**  
مواطن يبدأ عبر التطبيق.

**التسلسل الأساسي**
1. إنشاء/متابعة طلب عبر Flutter.
2. Backend يستقبل طلبات الأعمال.
3. تفاعلات خارجية (دفع/تخزين/Firebase عند الحاجة).
4. طلب مراجعة موظف.
5. قرار مراجعة/اختبار.
6. تحديث حالة الطلب وإشعار.

**المسارات البديلة**  
`loop` على دورة حياة الطلب من `draft` إلى `license_issued`.

**التحقق والأمان**  
مستوى عالٍ فقط؛ التفاصيل في الصفحات اللاحقة.

**تغييرات الحالة**  
تُلمَّح سلسلة الحالات دون تفصيل كل انتقال.

**البيانات التي يتم حفظها**  
طلب، وثائق، مدفوعات، مواعيد، نتائج، رخص، إشعارات (مفهومياً).

**الإشعارات الناتجة**  
إشعار حالة/إصدار بشكل مجمل.

**الـAPI الرئيسي**  
مجموعة Citizen/Admin APIs (انظر الصفحات التفصيلية).

**الطبقات البرمجية المرتبطة**  
خلفية DLMS كصندوق أسود.

**الاختبارات المرتبطة**  
حزم Application/Payment/License/AI مجتمعة.

**النتيجة النهائية**  
فهم تدفق النظام من البداية للنهاية.

---

### 02 - التسجيل وتسجيل الدخول

**الهدف**  
تسلسل التسجيل مع OTP والدخول والخروج.

**السيناريو**  
تسجيل حساب جديد ثم تفعيله ثم الدخول.

**المشاركون**  
Citizen، Flutter، Auth Controllers، AuthService، Database.

**نقطة البداية**  
`POST /api/auth/register`.

**التسلسل الأساسي**
1. Flutter → RegisterController → AuthService.register.
2. إنشاء مستخدم غير مفعّل + إرسال OTP (`OtpService`).
3. `POST /api/auth/verify-otp` → تفعيل الحساب وإصدار Sanctum token.
4. `POST /api/auth/login` بعد التحقق.
5. `POST /api/auth/logout` يحذف الـ token الحالي.

**المسارات البديلة**  
`alt [OTP غير صالح]` / `[بيانات دخول غير صحيحة]`؛ مسار نسيان كلمة المرور عبر OTP منفصل.

**التحقق والأمان**  
throttle على نسيان كلمة المرور؛ التحقق من `OtpPurpose::register`.

**تغييرات الحالة**  
`is_active` و`email_verified_at`.

**البيانات**  
`users`, OTP store, `personal_access_tokens`.

**الإشعارات**  
لا إشعار أعمال لهذه الخطوة.

**الـAPI**  
`/api/auth/register|verify-otp|login|logout` (+ forgot/reset).

**الطبقات**  
`RegisterController`, `LoginController`, `LogoutController`, `AuthService`, `OtpService`, `AuthRepository`.

**الاختبارات**  
`PasswordResetFlowTest`, `OtpDebugLoggingTest` وتغطيات تدفق غير مباشرة.

**النتيجة النهائية**  
جلسة مواطن مصادَقة أو فشل مصادقة.

---

### 03 - إنشاء وتحديث الملف الشخصي

**الهدف**  
إكمال/تحديث الملف وإدخاله للمراجعة.

**المشاركون**  
Citizen، Flutter، ProfileController، ProfileService، Database.

**نقطة البداية**  
`PUT /api/profile/complete` أو `update`.

**التسلسل الأساسي**
1. إرسال بيانات الملف.
2. ProfileService.completeProfile / updateProfile.
3. الانتقال إلى `pending_review` عند الإكمال أو التحديث الحساس.
4. الاستجابة بحالة الملف.

**المسارات البديلة**  
تحديث غير مكتمل؛ إعادة الإرسال بعد الرفض.

**التحقق والأمان**  
`auth:sanctum`؛ ملكية الملف للمواطن الحالي.

**تغييرات الحالة**  
`ProfileStatus`: `incomplete` → `pending_review` (ثم مراجعة في الصفحة 04).

**البيانات**  
حقول الملف في `users`.

**الإشعارات**  
عند قرار المراجع لاحقاً.

**الـAPI**  
`PUT /api/profile/complete`, `PUT /api/profile/update`, `GET /api/profile/status`.

**الطبقات**  
`ProfileController`, `AuthService` (تفويض), `ProfileService`.

**الاختبارات**  
`ProfileApprovalFlowTest`.

**النتيجة النهائية**  
ملف بانتظار المراجعة أو محدَّث.

---

### 04 - مراجعة الملف الشخصي

**الهدف**  
اعتماد/رفض الملف من الموظف.

**المشاركون**  
Profile Reviewer، Dashboard، ProfileReviewController، ProfileReviewService، Database، NotificationService.

**نقطة البداية**  
موظف يفتح طابور الملفات المعلّقة.

**التسلسل الأساسي**
1. تحميل ملفات `pending_review`.
2. `POST /api/admin/profile-reviews/{user}/approve|reject`.
3. تحديث `profile_status`.
4. إشعار المواطن (`profile.approved` / `profile.rejected`).

**المسارات البديلة**  
`alt [Approved]` / `[Rejected]` مع سبب الرفض.

**التحقق والأمان**  
`permission:review_profiles`.

**تغييرات الحالة**  
`approved` أو `rejected`؛ الاعتماد يفتح Middleware `profile.approved`.

**البيانات**  
`users.profile_status`, `profile_rejection_reason`, audit.

**الإشعارات**  
ProfileApproved / ProfileRejected (+ Push لاحقاً إن وُجد جهاز).

**الـAPI**  
`/api/admin/profile-reviews/*`.

**الطبقات**  
`ProfileReviewController`, `ProfileReviewService`, `AuditLogService`, `NotificationService`.

**الاختبارات**  
`ProfileApprovalFlowTest`.

**النتيجة النهائية**  
ملف معتمد أو مرفوض — **منفصل عن مراجعة الوثائق**.

---

### 05 - إنشاء طلب رخصة جديدة

**الهدف**  
إنشاء طلب `new_license` بحالة `draft`.

**المشاركون**  
Citizen، Flutter، ApplicationController، ApplicationService، ApplicationRepository/DB.

**نقطة البداية**  
`POST /api/applications` مع `profile.approved`.

**التسلسل الأساسي**
1. اختيار نوع الرخصة/الخدمة.
2. فحوصات أهلية/تكرار عبر ApplicationService.
3. `createDraftForCitizen` → `draft`.
4. إشعار `ApplicationCreated` (مختصر).
5. إرجاع الطلب.

**المسارات البديلة**  
ملف غير معتمد؛ تكرار طلب نشط؛ خدمة تتطلب `related_license_id` (ليست مسار جديد بحت).

**التحقق والأمان**  
`citizen` + `profile.approved`.

**تغييرات الحالة**  
→ `draft`.

**البيانات**  
`license_applications`, `application_status_histories`.

**الإشعارات**  
`application.created`.

**الـAPI**  
`POST /api/applications`.

**الطبقات**  
`ApplicationController`, `ApplicationService`, `ApplicationRepository`, `LicenseServiceEligibilityService` عند الحاجة.

**الاختبارات**  
`ApplicationFlowTest`, `OtherLicenseServicesFlowTest`.

**النتيجة النهائية**  
طلب مسودة جاهز للوثائق.

---

### 06 - رفع الوثائق

**الهدف**  
رفع ملف وثيقة دون إرسال الحزمة للمراجعة.

**المشاركون**  
Citizen، Flutter، ApplicationDocumentController، ApplicationDocumentService، Storage+DB.

**نقطة البداية**  
`POST /api/applications/{application}/documents`.

**التسلسل الأساسي**
1. رفع الملف.
2. تحقق الملكية/الحالة/الوثيقة المطلوبة.
3. تخزين خاص على القرص المحلي.
4. إنشاء/تحديث `application_documents` بحالة `pending_review`.

**المسارات البديلة**  
`alt [Valid]` / `[Invalid ownership/state/file]`.

**التحقق والأمان**  
ملكية الطلب؛ نوع الملف؛ حالة الطلب تسمح بالرفع.

**تغييرات الحالة**  
DocumentStatus → `pending_review`؛ **لا** يغيّر ApplicationStatus بمفرده.

**البيانات**  
`application_documents` + ملف مخزَّن.

**الإشعارات**  
لا عند الرفع وحده عادة.

**الـAPI**  
`POST /api/applications/{id}/documents`.

**الطبقات**  
`ApplicationDocumentService`.

**الاختبارات**  
`DocumentFlowTest`.

**النتيجة النهائية**  
وثيقة مرفوعة بانتظار الإرسال الجماعي للمراجعة.

---

### 07 - إرسال ومراجعة الوثائق

**الهدف**  
فصل `submit-documents` عن قرار المراجع.

**المشاركون**  
Citizen، Flutter، Backend APIs، Reviewer/Dashboard، DocumentReviewService، DB، NotificationService.

**نقطة البداية**  
`POST /api/applications/{id}/submit-documents`.

**التسلسل الأساسي**
1. submitForReview → `documents_under_review`.
2. المراجع يعتمد/يرفض عبر Admin أو Dashboard document-reviews.
3. عند اكتمال الاعتماد → `payment_pending`.
4. عند أي رفض → `documents_rejected` + سبب.

**المسارات البديلة**  
`alt [Approved all]` / `[Rejected]`.

**التحقق والأمان**  
`review_documents`؛ اكتمال الوثائق المطلوبة قبل الإرسال.

**تغييرات الحالة**  
طلب: `documents_under_review` → `payment_pending` | `documents_rejected`  
وثيقة: `approved` | `rejected`.

**البيانات**  
`application_documents`, histories، أسباب الرفض.

**الإشعارات**  
حالات الوثائق/الطلب + DocumentApproved/Rejected.

**الـAPI**  
`submit-documents`؛ `/api/admin/documents/{id}/approve|reject`؛ `/api/dashboard/document-reviews/...`.

**الطبقات**  
`ApplicationDocumentService`, `DocumentReviewService`, `DashboardDocumentReviewService`.

**الاختبارات**  
`DocumentFlowTest`, `DashboardDocumentReviewTest`, `DocumentReviewerAuthorizationTest`.

**النتيجة النهائية**  
جاهزية الدفع أو إعادة الرفع.

---

### 08 - المسار اليدوي للمواطن

**الهدف**  
إظهار أن Flutter يستدعي Citizen APIs مباشرة دون وكيل.

**المشاركون**  
Citizen، Flutter، DLMS Citizen APIs، Domain Services، DB.

**نقطة البداية**  
اختيار المسار اليدوي في التطبيق.

**التسلسل الأساسي**
1. استدعاءات متتابعة: إنشاء طلب → وثائق → دفع → مواعيد/اختبارات (لـ new_license) → انتظار الإصدار.
2. كل خطوة تمر عبر نفس خدمات المجال المستخدمة من الوكيل لاحقاً.

**المسارات البديلة**  
فشل أي مرحلة يوقف التقدم حسب قواعد تلك المرحلة.

**التحقق والأمان**  
Sanctum + profile.approved على العمليات المُغيِّرة.

**تغييرات الحالة**  
سلسلة الحالة الكاملة لـ new_license.

**البيانات**  
نفس جداول مسار الطلب.

**الإشعارات**  
حسب كل مرحلة.

**الـAPI**  
مجموعة `/api/applications/*` و`/appointments/*` و`/licenses/*`.

**الطبقات**  
خدمات Applications/Payments/Appointments/Licenses.

**الاختبارات**  
Application/Payment/Appointment/License Feature suites.

**النتيجة النهائية**  
مسار مواطن يدوي مكتمل مفهومياً.

---

### 09 - الوكيل الذكي — محادثة وقراءة البيانات

**الهدف**  
قراءة البيانات عبر الوكيل دون تأكيد اصطناعي.

**المشاركون**  
Citizen، Flutter Chat، AIAgentController، AIAgentService، Domain Query Service، (GeminiClient عند الاستدعاء الفعلي).

**نقطة البداية**  
`POST /api/ai-agent/message`.

**التسلسل الأساسي**
1. إرسال الرسالة.
2. تحليل النية/السياق.
3. إن كان الفعل قراءة فقط → `AIAgentActionService::executeReadOnlyNow` عبر خدمات الاستعلام.
4. إرجاع إجابة للمواطن.

**المسارات البديلة**  
نوايا غير مدعومة؛ معلومات ناقصة لطلب توضيح.

**التحقق والأمان**  
مواطن فقط؛ لا أفعال إدارية.

**تغييرات الحالة**  
لا mutation للأعمال.

**البيانات**  
جلسات/رسائل الوكيل؛ قراءة فقط من جداول الأعمال.

**الإشعارات**  
لا بسبب القراءة وحدها.

**الـAPI**  
`POST /api/ai-agent/message` (+ sessions).

**الطبقات**  
`AIAgentService`, `AIAgentActionService`, `GeminiAgentClient` (تقني).

**الاختبارات**  
`AIAgentFlowTest` ومجموعة اختبارات القراءة.

**النتيجة النهائية**  
معلومة معروضة دون تأكيد.

**ملاحظة**  
`get_license_details` و`get_notifications` مذكورتان في خريطة الأفعال لكنهما غير منفّذتين في Phase 9B/Executor.

---

### 10 - الوكيل الذكي — إجراء يحتاج تأكيد

**الهدف**  
معمارية التأكيد الإلزامية للأفعال المُغيِّرة.

**المشاركون**  
Citizen، Flutter Chat، AIAgentController، AIAgentService/Pending Action، Domain Service، Database.

**نقطة البداية**  
طلب فعل مُغيّر عبر المحادثة.

**التسلسل الأساسي**
1. الرسالة → اقتراح فعل بحالة `awaiting_confirmation`.
2. طلب تأكيد من المواطن.
3. `alt [Confirmed]`: `POST .../actions/{action}/confirm` → `AgentActionExecutor` → **خدمة المجال نفسها**.
4. `alt [Cancelled]`: cancel → **لا mutation**.
5. التحقق من الملكية/عدم كون الفعل قديماً (stale) قبل التنفيذ.

**المسارات البديلة**  
تأكيد/إلغاء؛ رفض أفعال غير قابلة للتنفيذ بعد (مثل renew/replace/unblock في Phase 9B).

**التحقق والأمان**  
ملكية الجلسة/الفعل؛ قائمة Phase 9B.

**تغييرات الحالة**  
`AgentActionStatus`؛ وحالات الطلب فقط عبر خدمة المجال بعد التأكيد.

**البيانات**  
`ai_agent_actions` / الجلسات؛ ثم جداول المجال عند التنفيذ.

**الإشعارات**  
حسب الفعل المنفَّذ في المجال.

**الـAPI**  
`/ai-agent/message`, `/ai-agent/actions/{id}/confirm|cancel`.

**الطبقات**  
`AIAgentActionService`, `AgentActionExecutor`, خدمات Applications/Payments/Appointments.

**الاختبارات**  
`AIAgentActionExecutionTest`, `AIAgentPhase1CriticalActionsTest`, `AIAgentPendingWorkflowReliabilityTest`.

**النتيجة النهائية**  
تنفيذ مجال أو إلغاء بلا أثر.

---

### 11 - رفع الوثائق عبر الوكيل الذكي

**الهدف**  
رفع وثيقة عبر endpoint الوكيل دون إرسال محتوى الملف إلى Gemini.

**المشاركون**  
Citizen، Flutter Chat، AIAgentController، AgentDocumentUploadService، ApplicationDocumentService، Storage/DB.

**نقطة البداية**  
`POST /api/ai-agent/sessions/{session}/documents`.

**التسلسل الأساسي**
1. رفع الملف المرتبط بالجلسة/الطلب.
2. AgentDocumentUploadService → ApplicationDocumentService.upload.
3. حفظ الملف والبيانات الوصفية.
4. إرسال للمراجعة يبقى فعلاً منفصلاً (قد يتطلب تأكيداً كـ `submit_documents_for_review`).

**المسارات البديلة**  
ملف غير صالح؛ جلسة/طلب غير مطابق.

**التحقق والأمان**  
ملكية الجلسة والطلب.

**تغييرات الحالة**  
Document `pending_review`؛ Application لا يتغير بالرفع وحده.

**البيانات**  
نفس `application_documents` + تخزين الملفات.

**الإشعارات**  
عند الإرسال للمراجعة لاحقاً.

**الـAPI**  
`POST /api/ai-agent/sessions/{session}/documents`.

**الطبقات**  
`AgentDocumentUploadService`, `ApplicationDocumentService`.

**الاختبارات**  
`AIAgentDocumentUploadTest`, `AIAgentConversationalDocumentFlowTest`.

**النتيجة النهائية**  
وثيقة مرفوعة عبر مسار الوكيل بنفس خدمة المجال.

---

### 12 - الدفع الإلكتروني

**الهدف**  
تسلسل الدفع الحقيقي: Mock وStripe وWebhook.

**المشاركون**  
Citizen، Flutter، ApplicationPaymentController، ApplicationPaymentService، Payment Gateway، PaymentLifecycleService/DB، NotificationService.

**نقطة البداية**  
طلب في `payment_pending` → `POST /api/applications/{id}/payments`.

**التسلسل الأساسي**
1. إنشاء Payment `pending`.
2. `alt [Mock]`: `.../confirm` → completeVerifiedPayment.
3. `alt [Stripe]`: Checkout ثم `POST /api/webhooks/stripe`.
4. عند الاكتمال من `payment_pending`: `payment_completed` ثم فوراً `appointment_pending` (new_license) أو `approved`.
5. إشعارات الدفع/الحالة.

**المسارات البديلة**  
`completed` / `failed` / `under_verification`.

**التحقق والأمان**  
ملكية الطلب؛ مزوّد الدفع من الإعدادات.

**تغييرات الحالة**  
PaymentStatus + ApplicationStatus كما أعلاه.

**البيانات**  
`payments`, `payment_gateway_events`, histories.

**الإشعارات**  
PaymentCompleted/Failed/UnderVerification + انتقالات الطلب.

**الـAPI**  
payments + confirm + `/api/webhooks/stripe`.

**الطبقات**  
`ApplicationPaymentService`, `PaymentLifecycleService`, `StripePaymentGatewayService`, `MockPaymentGatewayService`.

**الاختبارات**  
`PaymentFlowTest`, `PaymentStripeTest`, `PaymentConcurrencyAndIntegrityTest`.

**النتيجة النهائية**  
دفع مكتمل مع انتقال الطلب، أو فشل/تحت تحقق.

---

### 13 - العمليات المالية والمراجعة

**الهدف**  
تحقق الموظف المالي/التسوية.

**المشاركون**  
Financial Employee، Dashboard، DashboardPaymentController، DashboardPaymentService، PaymentReconciliationService، DB.

**نقطة البداية**  
`POST /api/dashboard/payments/{payment}/verify`.

**التسلسل الأساسي**
1. تحميل الدفعة من لوحة التحكم.
2. verify → reconcile.
3. تحديث حالة الدفع/الطلب عند الانطباق.
4. أثر إشعاري عبر دورة الاكتمال إن اكتمل التحقق.

**المسارات البديلة**  
دفعة غير قابلة للتحقق؛ تعارض يبقي `under_verification`.

**التحقق والأمان**  
صلاحيات لوحة المدفوعات.

**تغييرات الحالة**  
حسب نتيجة التسوية.

**البيانات**  
`payments` والأحداث المرتبطة.

**الإشعارات**  
عند اكتمال موثّق.

**الـAPI**  
`/api/dashboard/payments/{payment}/verify` ومسارات عرض المدفوعات.

**الطبقات**  
`DashboardPaymentService`, `PaymentReconciliationService`.

**الاختبارات**  
`DashboardPaymentManagementTest`.

**النتيجة النهائية**  
تسوية مالية موظف.

---

### 14 - حجز موعد

**الهدف**  
جلب الفترات والحجز.

**المشاركون**  
Citizen، Flutter، Appointment APIs، AppointmentService، DB، NotificationService.

**نقطة البداية**  
`GET /api/appointment-slots` ثم `POST /api/applications/{id}/appointments`.

**التسلسل الأساسي**
1. عرض الفترات المتاحة.
2. التحقق من نوع الاختبار الحالي والسعة.
3. إنشاء موعد `booked`.
4. إن كان الطلب `appointment_pending` → `in_testing`.
5. إشعار `appointment.booked`.

**المسارات البديلة**  
فترة غير متاحة؛ خدمة بلا اختبارات مرفوضة للحجز.

**التحقق والأمان**  
`profile.approved`؛ قفل السعة.

**تغييرات الحالة**  
Appointment `booked`؛ Application قد يصبح `in_testing`.

**البيانات**  
`test_appointments`, `appointment_slots`.

**الإشعارات**  
AppointmentBooked.

**الـAPI**  
`/appointment-slots`, `/applications/{id}/appointments`.

**الطبقات**  
`AppointmentService`, `TestProgressionService`.

**الاختبارات**  
`AppointmentFlowTest`, `AppointmentSlotConcurrencyTest`, `AppointmentNotificationTest`.

**النتيجة النهائية**  
موعد محجوز.

---

### 15 - تعديل أو إلغاء موعد

**الهدف**  
إعادة الجدولة أو الإلغاء.

**المشاركون**  
Citizen، Flutter، AppointmentController، AppointmentService، DB، NotificationService.

**نقطة البداية**  
موعد بحالة `booked`.

**التسلسل الأساسي**
1. `alt [Reschedule]`: `PUT /api/appointments/{id}/reschedule`.
2. `alt [Cancel]`: `DELETE /api/appointments/{id}/cancel` يحرر السعة **دون** تغيير ApplicationStatus تلقائياً.
3. إشعارات Rescheduled/Cancelled.

**المسارات البديلة**  
موعد غير قابل للتعديل؛ سعة الهدف غير كافية.

**التحقق والأمان**  
ملكية الموعد.

**تغييرات الحالة**  
Appointment: يبقى booked بعد النقل أو يصبح `cancelled`.

**البيانات**  
`test_appointments`, سعةslots.

**الإشعارات**  
AppointmentRescheduled / Cancelled.

**الـAPI**  
reschedule / cancel.

**الطبقات**  
`AppointmentService`.

**الاختبارات**  
`AppointmentFlowTest`.

**النتيجة النهائية**  
موعد معدَّل أو ملغى.

---

### 16 - تسجيل نتيجة اختبار

**الهدف**  
تسجيل نتيجة من الممتحن وتحديث مسار الطلب.

**المشاركون**  
Examiner، Dashboard، TestAppointmentResultController، TestResultService، DB، NotificationService.

**نقطة البداية**  
`POST /api/admin/test-appointments/{appointment}/record-result`.

**التسلسل الأساسي**
1. تحقق صلاحية `record_test_result` وحالة الطلب (`in_testing`/`waiting_retest`).
2. تسجيل النتيجة.
3. `alt [Pass]`: تقدّم للنوع التالي أو `approved` إذا اكتملت السلسلة.
4. `alt [Fail/No-show]`: `waiting_retest` أو `administrative_review` عند استنفاد المحاولات.
5. إشعارات النتيجة + الحالة.

**المسارات البديلة**  
Pass / Fail / No-show / max attempts.

**التحقق والأمان**  
صلاحية الممتحن؛ حالة الموعد.

**تغييرات الحالة**  
Appointment `completed`/`no_show`؛ Application كما أعلاه.

**البيانات**  
`test_results`, appointments, applications.

**الإشعارات**  
TestResult* + ApplicationWaitingRetest/Approved/AdministrativeReview.

**الـAPI**  
admin record-result.

**الطبقات**  
`TestResultService`, `TestProgressionService`, `ApplicationRepository`.

**الاختبارات**  
`LicenseFlowTest`, `AppointmentFlowTest`, `AvailableTestsApiTest`.

**النتيجة النهائية**  
تقدّم أو إعادة أو مراجعة إدارية.

---

### 17 - إعادة الاختبار

**الهدف**  
المسار بعد `waiting_retest` دون افتراض رسوم إعادة غير موجودة في التنفيذ.

**المشاركون**  
Citizen، Flutter، AppointmentService/Test services، DB.

**نقطة البداية**  
طلب في `waiting_retest`.

**التسلسل الأساسي**
1. المواطن يعيد حجز موعد لنفس نوع الاختبار الحالي.
2. الممتحن يسجل نتيجة جديدة (انظر 16).
3. النجاح يُكمل التسلسل؛ الرسوب يعيد التقييم مقابل `max_attempts`.

**المسارات البديلة**  
استنفاد المحاولات → `administrative_review`.

**التحقق والأمان**  
نفس قواعد الحجز/التسجيل.

**تغييرات الحالة**  
`waiting_retest` ↔ `in_testing` / `approved` / `administrative_review`.

**البيانات**  
مواعيد ونتائج إضافية.

**الإشعارات**  
حجز + نتيجة.

**الـAPI**  
نفس مواعيد/نتائج.

**الطبقات**  
`AppointmentService`, `TestResultService`, `TestProgressionService`.

**الاختبارات**  
تدفقات المواعيد/الرخص.

**النتيجة النهائية**  
إعادة محاولة أو تصعيد إداري.  
**ملاحظة:** لا تُبتكر رسوم إعادة اختبار في المخطط لأنها غير مفروضة كخطوة منفصلة في التنفيذ الحالي لهذا المسار.

---

### 18 - إصدار الرخصة

**الهدف**  
إصدار الرخصة بعد الأهلية.

**المشاركون**  
Issuance Employee، Dashboard، ApplicationLicenseController، LicenseService، DB، NotificationService.

**نقطة البداية**  
`POST /api/admin/applications/{application}/issue-license`.

**التسلسل الأساسي**
1. `LicenseIssuanceEligibilityService::assertReady`.
2. `LicenseService::issueForApplication` (فروع new/renew/replace).
3. Application → `license_issued`؛ رخصة `active` (+ تحديث الرخصة القديمة عند التجديد/البدل).
4. إشعار `license.issued` (ملاحظة: خريطة إشعار حالة `LicenseIssued` للتطبيق تُرجع null؛ الإشعار المباشر هو المعتمد).

**المسارات البديلة**  
عدم استيفاء الشروط.

**التحقق والأمان**  
`issue_license`.

**تغييرات الحالة**  
`approved` → `license_issued`؛ LicenseStatus accordingly.

**البيانات**  
`licenses`, histories, applications.

**الإشعارات**  
LicenseIssued.

**الـAPI**  
issue-license.

**الطبقات**  
`LicenseService`, `LicenseLifecycleService`, Eligibility.

**الاختبارات**  
`LicenseFlowTest`, `LicensePrintingTest`, `OtherLicenseServicesFlowTest`.

**النتيجة النهائية**  
رخصة صادرة.

---

### 19 - التجديد

**الهدف**  
تمييز مساري التجديد.

**المشاركون**  
Citizen، Flutter، Application path services / LicenseController، LicenseService، DB.

**نقطة البداية**  
اختيار تجديد.

**التسلسل الأساسي**
1. `alt [طلب تطبيق]`: create `renew_license` + related license → وثائق → دفع → `approved` (بلا اختبارات) → إصدار موظف.
2. `alt [مباشر]`: `POST /api/licenses/{id}/renew` → رخصة جديدة `active` وقديمة `renewed` **بدون إشعار LicenseIssued في هذا المسار المباشر**.

**المسارات البديلة**  
عدم الأهلية؛ فشل دفع في مسار الطلب.

**التحقق والأمان**  
`profile.approved`؛ أهلية الرخصة.

**تغييرات الحالة**  
كما فوق حسب المسار.

**البيانات**  
applications/payments أو licenses مباشرة.

**الإشعارات**  
مسار الطلب عبر المراحل؛ المباشر بلا إشعار إصدار مباشر.

**الـAPI**  
`POST /applications` + pipeline؛ `POST /licenses/{id}/renew`.

**الطبقات**  
`ApplicationService`, `LicenseService`, Eligibility.

**الاختبارات**  
`LicenseFlowTest`, `OtherLicenseServicesFlowTest`.

**النتيجة النهائية**  
رخصة مجددة.

---

### 20 - بدل فاقد أو تالف

**الهدف**  
تمييز Lost/Damaged ومساري التطبيق/المباشر.

**المشاركون**  
Citizen، Flutter، Application/License APIs، LicenseService، DB.

**نقطة البداية**  
اختيار نوع الاستبدال.

**التسلسل الأساسي**
1. `alt [Lost]` / `[Damaged]` (رسوم مختلفة في مسار الطلب).
2. `alt [Application]` وثائق+دفع → إصدار بدل.
3. `alt [Direct]` `POST /api/licenses/{id}/replacement` → جديدة `active` وقديمة `inactive` (بلا إشعار مباشر).

**المسارات البديلة**  
أهلية مرفوضة؛ فشل دفع.

**التحقق والأمان**  
ملكية الرخصة؛ نوع البدل.

**تغييرات الحالة**  
حسب المسار؛ القديمة `inactive` عند الاستبدال.

**البيانات**  
applications أو licenses.

**الإشعارات**  
مسار الطلب عبر المراحل.

**الـAPI**  
applications + `/licenses/{id}/replacement`.

**الطبقات**  
`LicenseService@replace` / `issueReplacementLicense`.

**الاختبارات**  
`OtherLicenseServicesFlowTest`.

**النتيجة النهائية**  
رخصة بديلة.

---

### 21 - مركز الإشعارات داخل التطبيق

**الهدف**  
واجهات مركز الإشعارات دون Firebase.

**المشاركون**  
Citizen، Flutter، NotificationController، NotificationService، Database.

**نقطة البداية**  
فتح مركز الإشعارات.

**التسلسل الأساسي**
1. `GET /api/notifications`
2. `GET /api/notifications/unread-count`
3. `PUT /api/notifications/{id}/read`
4. `PUT /api/notifications/read-all`

**المسارات البديلة**  
إشعار غير مملوك → رفض.

**التحقق والأمان**  
ملكية الإشعار للمستخدم.

**تغييرات الحالة**  
علامات القراءة على `notifications`.

**البيانات**  
`notifications`.

**الإشعارات**  
هذه الصفحة تستهلك الإشعارات ولا تنشئها.

**الـAPI**  
كما أعلاه.

**الطبقات**  
`NotificationService`, `NotificationRepository`.

**الاختبارات**  
`NotificationCenterApiTest`, `NotificationUnreadCountTest`, `NotificationReadAllTest`, `NotificationIdempotencyTest`.

**النتيجة النهائية**  
عرض/قراءة إشعارات DB.

**ملاحظة لغوية**  
نص الإشعار المخزَّن يعتمد على `users.language` للمستلم؛ `Accept-Language` يؤثر على استجابة API الحالية لا على إعادة ترجمة Push في Flutter.

---

### 22 - تسجيل جهاز Firebase Push

**الهدف**  
تسجيل/تدوير/إلغاء رمز الجهاز.

**المشاركون**  
Citizen، Flutter، Firebase Messaging SDK، PushDeviceController، PushDeviceService، DB.

**نقطة البداية**  
Flutter يحصل على FCM token.

**التسلسل الأساسي**
1. SDK → token.
2. `POST /api/devices/push-token` (device_id, platform, token).
3. تخزين داخلي آمن (hash/تشفير داخلي — بلا أسرار في المخطط).
4. `opt` onTokenRefresh → نفس الـ endpoint.
5. إمكانية `DELETE /api/devices/push-token`.

**المسارات البديلة**  
رمز غير صالح؛ تدوير رمز.

**التحقق والأمان**  
مصادقة المواطن؛ عدم إرجاع أسرار الخدمة.

**تغييرات الحالة**  
سجلات `push_devices`.

**البيانات**  
`push_devices`.

**الإشعارات**  
لا إنشاء إشعار هنا.

**الـAPI**  
`/api/devices/push-token`.

**الطبقات**  
`PushDeviceService`, `PushDeviceRepository`.

**الاختبارات**  
`PushDeviceRegistrationTest`, `PushDeviceTokenRotationTest`, `PushDeviceSecurityTest`.

**النتيجة النهائية**  
جهاز مسجل لاستقبال Push.

---

### 23 - إرسال Push عبر Firebase

**الهدف**  
خط أنابيب التسليم غير المتزامن.

**المشاركون**  
DomainService، NotificationService، Database، PushDeliveryService، Queue، SendPushNotificationJob، FcmClient، Firebase FCM.

**نقطة البداية**  
حدث أعمال يستدعي `NotificationService::notify`.

**التسلسل الأساسي**
1. بعد نجاح المعاملة: `DB::afterCommit`.
2. Persist إشعار DB (مصدر الحقيقة).
3. `planPushSafely` → `planForNotification`.
4. إنشاء `push_deliveries` و`dispatch` Job (**async**).
5. Worker ينفّذ لاحقاً → FcmClient → FCM.
6. `loop` لكل جهاز مسجل.
7. `alt [sent]` / `[UNREGISTERED→invalid_token]` / `[retryable مع backoff 60/120/300/900]` / `[permanent fail]`.

**المسارات البديلة**  
كما في الـ alt أعلاه.

**التحقق والأمان**  
لا تُعرض اعتمادات Service Account؛ فشل FCM لا يراجع عملية الأعمال.

**تغييرات الحالة**  
`PushDeliveryStatus`: pending→processing→sent|failed|invalid_token.

**البيانات**  
`notifications`, `push_deliveries`, `push_devices`.

**الإشعارات**  
DB أولاً؛ Push قناة تسليم.

**الـAPI**  
لا HTTP مواطن مباشر؛ يستدعى من خدمات المجال.

**الطبقات**  
`NotificationService`, `PushDeliveryService`, `SendPushNotificationJob`, `FcmClient`.

**الاختبارات**  
`PushDeliveryPlanningTest`, `PushDeliveryRetryTest`, `SendPushNotificationJobTest`, `FcmClientTest`, `PushProductionCertificationTest`.

**النتيجة النهائية**  
محاولة تسليم مع بقاء إشعار DB صالحاً دائماً.

**إعدادات مهمة**  
backoff `[60,120,300,900]` ثانية؛ محاولات FCM افتراضياً 5؛ job tries 25؛ timeout 60؛ lease 180 (`config/firebase.php`).

---

### 24 - إلغاء تسجيل جهاز وتسجيل الخروج

**الهدف**  
الترتيب الصحيح: حذف جهاز Push ثم Logout.

**المشاركون**  
Citizen، Flutter، PushDeviceController، LogoutController، DB.

**نقطة البداية**  
المواطن يسجّل خروجاً من جهاز واحد.

**التسلسل الأساسي**
1. `DELETE /api/devices/push-token` للجهاز الحالي فقط.
2. ثم `POST /api/auth/logout` لحذف token الجلسة الحالي.
3. بقية أجهزة المستخدم تبقى مسجّلة.

**المسارات البديلة**  
محاولة logout دون unregister (يُنصح ضدها في العميل).

**التحقق والأمان**  
جلسة حالية فقط تُلغى.

**تغييرات الحالة**  
حذف/تعطيل جهاز؛ حذف access token.

**البيانات**  
`push_devices`, tokens.

**الإشعارات**  
لا.

**الـAPI**  
devices + logout.

**الطبقات**  
`PushDeviceService`, `AuthService@logout`.

**الاختبارات**  
Push device + auth logout تغطيات.

**النتيجة النهائية**  
خروج من جهاز واحد دون إزالة بقية الأجهزة.

---

### 25 - مراجعة الملفات الشخصية والوثائق

**الهدف**  
سياق موظف واحد بقدرتين منفصلتين.

**المشاركون**  
Reviewer، Dashboard، ProfileReviewService، DocumentReviewService، DB، NotificationService.

**نقطة البداية**  
دخول طابور المراجعة.

**التسلسل الأساسي**
1. فرع الملف الشخصي: approve/reject profile (صفحة 04).
2. فرع الوثائق: approve/reject document وتأثير حالة الطلب (صفحة 07).
3. كل فرع يُبقي دلالته التجارية منفصلة رغم اتحاد الدور/الواجهة.

**المسارات البديلة**  
اعتماد/رفض لكل فرع.

**التحقق والأمان**  
`review_profiles` و/أو `review_documents`.

**تغييرات الحالة**  
ProfileStatus أو Document/Application statuses.

**البيانات**  
users أو application_documents + applications.

**الإشعارات**  
حسب الفرع.

**الـAPI**  
admin profile-reviews + admin/dashboard documents.

**الطبقات**  
`ProfileReviewService`, `DocumentReviewService`, wrappers للوحة.

**الاختبارات**  
`ProfileApprovalFlowTest`, `DashboardDocumentReviewTest`.

**النتيجة النهائية**  
وضوح الفصل التشغيلي بين المراجعتين.

---

### 26 - إدارة الموظفين والصلاحيات

**الهدف**  
إدارة موظفين وأدوار وصلاحيات.

**المشاركون**  
Admin/Super Admin، Dashboard، Employee/AccessControl Controllers، DashboardEmployeeService / AccessControlService، DB.

**نقطة البداية**  
عمليات `/api/dashboard/employees` أو `/access-control`.

**التسلسل الأساسي**
1. إنشاء/تحديث موظف وتعيين دور.
2. إدارة الصلاحيات عبر Access Control (`super_admin`).
3. فحوصات permission على الطلبات اللاحقة.

**المسارات البديلة**  
رفض بسبب نقص صلاحية؛ حماية Super Admin.

**التحقق والأمان**  
`manage_employees`, `super_admin`.

**تغييرات الحالة**  
نشاط الموظف/ارتباط الأدوار.

**البيانات**  
`users`, `roles`, `permissions` والجداول المرتبطة.

**الإشعارات**  
ليست جوهر هذا التسلسل.

**الـAPI**  
`/dashboard/employees`, `/roles`, `/permissions`, `/access-control/*`.

**الطبقات**  
`DashboardEmployeeService`, `DashboardAccessControlService`, `DashboardRoleService`.

**الاختبارات**  
`EmployeeManagementTest`, `DashboardAccessControlTest`, `DashboardPermissionTest`, `SuperAdminProtectionTest`.

**النتيجة النهائية**  
تهيئة RBAC فعّالة.

---

### 27 - جلسات الموظفين

**الهدف**  
دورة جلسة موظف لوحة التحكم.

**المشاركون**  
Employee، Dashboard، DashboardAuth/EmployeeSessionController، EmployeeSessionService، DB.

**نقطة البداية**  
`POST /api/dashboard/auth/login`.

**التسلسل الأساسي**
1. تسجيل دخول لوحة → `startDashboardSession`.
2. تتبع النشاط/heartbeat عبر middleware.
3. عرض/إبطال جلسات بواسطة `root_super_admin` عند توفر المسارات.
4. تسجيل خروج صريح يحدّث حالة الجلسة.

**المسارات البديلة**  
إبطال جلسة واحدة أو كل جلسات موظف؛ انتهاء صلاحية.

**التحقق والأمان**  
`root_super_admin` لمسار إدارة الجلسات الحساس.

**تغييرات الحالة**  
`EmployeeSessionStatus`: active/idle/expired/logged_out/revoked.

**البيانات**  
`employee_sessions` (وما يرتبط).

**الإشعارات**  
ليست جوهرية.

**الـAPI**  
dashboard auth + `/dashboard/employee-sessions/*`.

**الطبقات**  
`DashboardAuthService`, `EmployeeSessionService`.

**الاختبارات**  
`DashboardEmployeeSessionsTest`, `EmployeeSessionLifecycleTest`, `EmployeeSessionRevocationTest`, `EmployeeSessionSecurityTest`.

**النتيجة النهائية**  
تحكم بجلسات الموظفين.

---

## 6. المسار اليدوي

في المسار اليدوي يستدعي Flutter واجهات المواطن مباشرة:

Citizen → Flutter → Controller → Domain Service → Persistence

بدون طبقة وكيل. كل قواعد الأهلية والحالة تُطبَّق في خدمات المجال نفسها.

---

## 7. مسار الوكيل الذكي

Citizen → Flutter Chat → AIAgentController → AIAgentService  
→ (قراءة فقط: تنفيذ فوري)  
→ (فعل مُغيّر: awaiting_confirmation → Confirm/Cancel)

عند التأكيد فقط: `AgentActionExecutor` → **نفس** Domain Service.

عند الإلغاء: لا كتابة أعمال.

قيود Phase 9B: تجديد/بدل/فك حظر قابلة للاقتراح كمُغيِّرة لكن غير قابلة للتنفيذ بعد في المنفّذ الحالي.

لغة جلسة الوكيل منفصلة مفهومياً عن `users.language` لإشعارات النظام.

---

## 8. الدفع

المزوّد من الإعدادات: `mock` أو `stripe`.

- Mock: تأكيد صريح عبر API.
- Stripe: Checkout + **Webhook حقيقي** `/api/webhooks/stripe`.
- الاكتمال يمر عبر `PaymentLifecycleService::completeVerifiedPayment`.
- موظف المالية يتحقق عبر Dashboard reconcile/verify.
- لا تُختلق callbacks غير موجودة.

---

## 9. الإشعارات وFirebase

```
حدث أعمال
→ NotificationService.notify
→ afterCommit
→ Persist DB notification  (Source of Truth)
→ planPushSafely
→ PushDeliveryService.planForNotification
→ Queue Job (async)
→ FcmClient → Firebase FCM
```

فشل FCM لا يلغي الإشعار ولا يراجع معاملة الأعمال.

---

## 10. الـQueue والعمليات غير المتزامنة

طلب HTTP ينتهي بعد حفظ الإشعار وتخطيط التسليم.  
العامل لاحقاً يرسل إلى FCM.  
إعادة المحاولة بـ backoff: 60 → 120 → 300 → 900 ثانية ضمن حدود المحاولات في `config/firebase.php`.

---

## 11. حالات الطلب المهمة

| الانتقال | المحفّز |
|---------|---------|
| → `draft` | إنشاء طلب |
| → `documents_under_review` | submit documents |
| → `documents_rejected` | رفض وثيقة |
| → `payment_pending` | اعتماد كل الوثائق |
| → `payment_completed` ثم `appointment_pending`/`approved` | اكتمال دفع موثّق |
| → `in_testing` | حجز من `appointment_pending` |
| → `waiting_retest` / `administrative_review` / `approved` | نتائج الاختبار |
| → `license_issued` | إصدار |

`rejected`/`cancelled`: معرفتان بلا caller إنتاجي حالياً.

---

## 12. مصفوفة التتبع

| Diagram | Actor | API | Controller | Main Service | Persistence | Tests |
|---------|-------|-----|------------|--------------|-------------|-------|
| 01 Overview | Citizen/Employee | مجمّع | — | Backend | — | suites |
| 02 Auth | Citizen | `/auth/*` | Register/Login/Logout | AuthService | users/otp/tokens | PasswordResetFlowTest |
| 03 Profile | Citizen | `/profile/*` | ProfileController | ProfileService | users | ProfileApprovalFlowTest |
| 04 Profile Review | Reviewer | `/admin/profile-reviews/*` | ProfileReviewController | ProfileReviewService | users | ProfileApprovalFlowTest |
| 05 Create App | Citizen | `POST /applications` | ApplicationController | ApplicationService | license_applications | ApplicationFlowTest |
| 06 Upload Docs | Citizen | `POST .../documents` | ApplicationDocumentController | ApplicationDocumentService | application_documents+storage | DocumentFlowTest |
| 07 Review Docs | Citizen/Reviewer | submit + admin/dashboard docs | DocumentReview* | DocumentReviewService | docs+applications | DocumentFlowTest |
| 08 Manual | Citizen | Citizen APIs | متعدد | خدمات المجال | متعدد | flow suites |
| 09 AI Read | Citizen | `/ai-agent/message` | AIAgentController | AIAgentService | agent+queries | AIAgentFlowTest |
| 10 AI Confirm | Citizen | confirm/cancel | AIAgentController | AIAgentActionService/Executor | agent+domain | AIAgentActionExecutionTest |
| 11 AI Docs | Citizen | sessions/.../documents | AIAgentController | AgentDocumentUploadService | docs/storage | AIAgentDocumentUploadTest |
| 12 Payment | Citizen | payments + webhook | ApplicationPayment/StripeWebhook | PaymentLifecycleService | payments | PaymentFlowTest/Stripe |
| 13 Finance | Employee | `/dashboard/payments/{id}/verify` | DashboardPaymentController | DashboardPaymentService | payments | DashboardPaymentManagementTest |
| 14 Book | Citizen | appointments/slots | Appointment* | AppointmentService | appointments | AppointmentFlowTest |
| 15 Reschedule/Cancel | Citizen | reschedule/cancel | AppointmentController | AppointmentService | appointments | AppointmentFlowTest |
| 16 Test Result | Examiner | record-result | TestAppointmentResultController | TestResultService | test_results | License/Appointment tests |
| 17 Retest | Citizen | appointments + results | كما 14/16 | TestProgressionService | كما أعلاه | كما أعلاه |
| 18 Issue | Issuer | issue-license | ApplicationLicenseController | LicenseService | licenses | LicenseFlowTest |
| 19 Renew | Citizen | applications + `/licenses/{id}/renew` | Application/License | LicenseService | apps/licenses | OtherLicenseServicesFlowTest |
| 20 Replace | Citizen | applications + replacement | Application/License | LicenseService | apps/licenses | OtherLicenseServicesFlowTest |
| 21 Notif Center | Citizen | `/notifications*` | NotificationController | NotificationService | notifications | NotificationCenterApiTest |
| 22 Push Device | Citizen | `/devices/push-token` | PushDeviceController | PushDeviceService | push_devices | PushDeviceRegistrationTest |
| 23 FCM Push | System | — | — | Notification/PushDelivery/FcmClient | notifications/deliveries | PushDelivery* tests |
| 24 Logout device | Citizen | DELETE token + logout | PushDevice/Logout | PushDevice/Auth | devices/tokens | Push/Auth tests |
| 25 Emp Reviews | Reviewer | admin/dashboard review APIs | Profile/Document Review | Profile/Document Review services | users/docs | Profile+Document tests |
| 26 RBAC | Admin | `/dashboard/employees|access-control` | DashboardEmployee/AccessControl | Employee/AccessControl services | roles/users | EmployeeManagement/AccessControl |
| 27 Sessions | Employee | dashboard auth/sessions | DashboardAuth/EmployeeSession | EmployeeSessionService | employee_sessions | EmployeeSession* |

---

## 13. مصفوفة Use Case → Sequence Diagram

| Use Case / Workflow | Diagram Page | Coverage |
|---------------------|--------------|----------|
| Account registration + OTP | 02 | FULL |
| Login/Logout | 02, 24 | FULL |
| Profile create/update | 03 | FULL |
| Profile review | 04, 25 | FULL |
| New license application | 05, 08 | FULL |
| Required documents / upload | 06 | FULL |
| Document submit/review | 07, 25 | FULL |
| Payment | 12 | FULL |
| Financial verification | 13 | FULL |
| Appointment booking | 14 | FULL |
| Reschedule/Cancel | 15 | FULL |
| Test result | 16 | FULL |
| Retest | 17 | FULL |
| License issuance | 18 | FULL |
| Renewal | 19 | FULL |
| Lost/Damaged replacement | 20 | FULL |
| Application tracking (status reads) | 08, 09 | PARTIAL |
| AI Agent read-only | 09 | FULL |
| AI Agent mutating+confirm | 10 | FULL |
| AI document upload | 11 | FULL |
| Notification Center | 21 | FULL |
| Firebase device registration | 22 | FULL |
| Firebase push delivery | 23 | FULL |
| Unregister + logout | 24 | FULL |
| Employee review ops | 25 | FULL |
| RBAC | 26 | FULL |
| Employee sessions | 27 | FULL |
| Application rejected/cancelled transitions | — | NOT IMPLEMENTED |
| AI renew/replace/unblock execute | 10 (قيد) | PARTIAL |
| Chatbot prefix فارغ | — | NOT IMPLEMENTED |

---

## 14. الفروقات بين الوثائق والتنفيذ

1. لا يوجد `planPush` كاسم دالة — الصحيح `planPushSafely` / `planForNotification`.
2. `rejected`/`cancelled` في Enum بلا انتقال إنتاجي.
3. مباشر renew/replace لا يرسلان `LicenseIssued`.
4. `requestUnblock` المباشر لا ينشئ طلب تطبيق كامل.
5. بعض أفعال الوكيل في الخريطة غير قابلة للتنفيذ في Phase 9B.
6. بادئة `chatbot` في `routes/api.php` فارغة/غير منفذة.
7. عند تعارض مخطط النشاط مع الكود: التسلسل يتبع الكود ويوثق الفرق.

---

## 15. الملاحظات المعمارية

- **خدمات مجال مشتركة** بين المسار اليدوي والوكيل.
- **Push غير متزامن** بعد `afterCommit`؛ لا ينتظره طلب الأعمال.
- **DB Notification = Source of Truth**.
- **متعدد الأجهزة** عبر `loop` على `push_devices`.
- **تأكيد الوكيل** إلزامي لأي mutation منفَّذ.
- **فصل مراجعتي الملف والوثائق** دلالياً حتى لو اتحد الدور.
- **مسارات مزدوجة** للتجديد والبدل (تطبيق مقابل API مباشر).

تصنيف سريع لمسارات الأعمال:

| Route class | Classification |
|-------------|----------------|
| Auth/Profile/Applications/Docs/Payments/Appointments/Licenses/AI/Notifications/Devices/Admin review/Issue | SEQUENCE-COVERED |
| Catalog GETs, reports, settings, content FAQs | SUPPORTING / NOT SEQUENCE-WORTHY |
| Empty chatbot group | DEPRECATED/NOT IMPLEMENTED |

---

## 16. الخلاصة

توفر الحزمة **27** صفحة Sequence Diagram قابلة للتحرير تغطي التفاعلات الرئيسية المنفَّذة في SYRTAK، مع نمذجة صحيحة للتأكيد في الوكيل، وللـ Queue/Firebase غير المتزامن، وللفصل بين مركز الإشعارات وقناة Push. التقرير العربي يربط كل صفحة بالمسارات والخدمات والاختبارات الفعلية، ويصرّح بالفجوات دون اختراع سلوك.

مستوى الثقة: **عالٍ** للمسارات الأساسية المغطاة بالاختبارات؛ **متوسط** لبعض المسارات الإدارية الثانوية الأقل اختباراً تفصيلاً؛ **مقيَّد صراحة** للأفعال/الحالات غير المنفَّذة.

---

*SYRTAK / DLMS — Sequence Diagrams package — source of truth: current backend implementation and automated tests.*
