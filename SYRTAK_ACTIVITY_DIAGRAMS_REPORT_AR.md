# مخططات النشاط لنظام سيرتك — SYRTAK

## 1. مقدمة

يمثل هذا المستند الحزمة التوثيقية النهائية لمخططات النشاط (UML Activity Diagrams) لنظام **سيرتك / DLMS** كما هو مُنفَّذ حالياً في المستودع.

### الغرض
توضيح العمليات التجارية الفعلية للمواطن والموظفين والوكيل الذكي، بما يشمل نقاط القرار، المسارات البديلة، وتغيّر الحالات (Status Transitions)، دون الاعتماد على افتراضات عامة لأنظمة الترخيص.

### نطاق النظام المشمول
- مصادقة المواطن والملف الشخصي ومراجعته
- دورة طلب الرخصة (جديد / تجديد / بدل فاقد أو تالف / فك حظر عبر الطلب حيث ينطبق)
- الوثائق والدفع والمواعيد والاختبارات وإصدار الرخصة
- مسار الوكيل الذكي (AI Agent) مع تأكيد الأفعال المُغيِّرة
- الإشعارات داخل التطبيق وتسليم Firebase Push
- عمليات لوحة التحكم والصلاحيات (RBAC)

### مصدر الحقيقة
أولوية المصادر عند التعارض:

1. التنفيذ التنفيذي الحالي للخلفية (Controllers / Services / Repositories)
2. الاختبارات الآلية Feature/Integration
3. تعدادات الحالة Enums وقاعدة البيانات
4. عقود الـ API والمسارات
5. وثائق المشروع الحالية
6. وثائق تصميم قديمة (إن وُجدت)

الملف الرسومي المرافق: `SYRTAK_COMPLETE_ACTIVITY_DIAGRAMS.drawio` (قابل للتحرير في diagrams.net / draw.io).

### ترميز UML المعتمد
استُخدم ترميز مخططات النشاط UML فقط (لا BPMN، ولا مخططات تسلسل). يشمل ذلك: Initial Node، Activity Final، Action، Decision/Merge، Fork/Join عند التوازي الحقيقي، وSwimlanes للجهات المشاركة.

---

## 2. منهجية بناء المخططات

1. **تدقيق المسارات**: `routes/api.php` وملفات Routes للوحدات (Dashboard, Admin, AIAgent, Settings, Content) و`routes/web.php`.
2. **تدقيق الطبقات**: Controllers → Form Requests → Services → Repositories → Models.
3. **تدقيق الحالات**: `ApplicationStatus`, `ProfileStatus`, `DocumentStatus`, `PaymentStatus`, `AppointmentStatus`, `TestResultStatus`, `LicenseStatus` وEnums الوكيل الذكي.
4. **تدقيق الانتقالات**: استدعاءات `ApplicationRepository::transitionStatus` من خدمات الوثائق والدفع والمواعيد والاختبارات والإصدار.
5. **تدقيق الوكيل الذكي**: `AgentWorkflowActionMap`, `AgentSafetyRules`, مسارات confirm/cancel، و`AgentActionExecutor`.
6. **تدقيق الاختبارات**: حزم Feature لتدفقات الطلب والدفع والمواعيد والرخص والوكيل والإشعارات والصلاحيات.
7. **مقارنة الوثائق القديمة**: لم يُعثر على حزمة Activity Diagrams سابقة؛ يوجد ERD منفصل `SYRTAK_COMPLETE_ERD.drawio` استُخدم للتناسق البصري فقط دون نسخ سلوك قديم.

---

## 3. دليل الرموز

| الرمز | المعنى |
|------|--------|
| ● Initial Node | بداية العملية |
| ◎ Activity Final | نهاية العملية |
| مستطيل دائري الزوايا | Action / نشاط |
| معين (Decision) | نقطة قرار |
| شرط على السهم `[...]` | Guard Condition |
| شريط سميك Fork/Join | تفرع/اندماج متوازٍ (يُستخدم فقط عند التوازي الحقيقي) |
| Swimlane | ممر مسؤولية لجهة/نظام |
| ملاحظة نصية | قيد تنفيذي أو توضيح حالة آلة |

أسماء الحالات الآلية تُعرض بالإنجليزية كما في الكود (مثال: `documents_under_review`) بجانب تسمية عربية موجزة للنشاط.

---

## 4. الجهات المشاركة

الجهات المستخدمة في المخططات مستمدة من التنفيذ الفعلي:

| الجهة | الدور |
|------|------|
| المواطن (Citizen) | تنفيذ مسارات الخدمة عبر التطبيق |
| الوكيل الذكي (AI Agent) | مسار مساعد منفصل؛ يقترح الأفعال المُغيِّرة وينفّذ بعد التأكيد عبر خدمات النطاق |
| نظام DLMS / Backend | التحقق، الانتقالات، والخدمات المشتركة |
| مراجع الملف الشخصي | اعتماد/رفض `ProfileStatus` |
| مراجع الوثائق | اعتماد/رفض وثائق الطلب |
| الموظف المالي | تحقق/تسوية المدفوعات في لوحة التحكم |
| الممتحن (Tester) | تسجيل نتائج الاختبارات |
| موظف الإصدار | إصدار الرخصة بعد الأهلية |
| المدير / Super Admin | الموظفون، الأدوار، الصلاحيات، الجلسات |
| بوابة الدفع | Mock أو Stripe (+ webhook) |
| نظام الإشعارات | إنشاء إشعار قاعدة البيانات |
| Queue | مهمة تسليم Push |
| Firebase FCM | قناة تسليم الدفع فقط |
| جهاز المواطن | استقبال Push وعرض مركز الإشعارات |

---

## 5. شرح كل مخطط

### 01 - النظام الشامل | System Overview

**الهدف**  
عرض المسار الشامل من المصادقة حتى إصدار الرخصة بشكل مناسب للعرض.

**الجهات/Swimlanes**  
مواطن، الوكيل الذكي، نظام DLMS، موظفو المراجعة، إكمال.

**الشروط المسبقة**  
لا يوجد؛ نقطة الدخول العامة للنظام.

**المسار الأساسي**
1. تسجيل/دخول مع OTP عند التسجيل.
2. جاهزية الملف الشخصي.
3. اختيار [يدوي / Manual] أو [وكيل ذكي / AI Agent].
4. إنشاء طلب `draft` ثم الوثائق فالمراجعة فالدفع.
5. إن كانت الخدمة `new_license` → مواعيد واختبارات؛ وإلا اعتماد مباشر نحو الإصدار.
6. إصدار الرخصة وإشعار المواطن → `license_issued`.

**المسارات البديلة والاستثنائية**  
مسار بلا اختبارات لخدمات التجديد/البدل؛ رفض الوثائق يُفصَّل في مخطط 05.

**نقاط القرار**  
طريقة الخدمة؛ هل الخدمة تتطلب اختبارات.

**تغييرات الحالة**  
`draft` → … → `payment_pending` → (`appointment_pending`/`approved`) → `license_issued`.

**النتيجة النهائية**  
رخصة صادرة أو توقف عند مرحلة وسيطة حسب حالة الطلب.

**الارتباط بالتنفيذ**  
`ApplicationService`, `ApplicationDocumentService`, `PaymentLifecycleService`, `AppointmentService`, `TestResultService`, `LicenseService`, `AIAgentService`.

---

### 02 - التسجيل وتسجيل الدخول

**الهدف**  
توثيق تسجيل الحساب، التحقق بـ OTP، الدخول، واستعادة كلمة المرور.

**الجهات/Swimlanes**  
مواطن، نظام DLMS.

**الشروط المسبقة**  
لا يوجد حساب مفعّل، أو وجود حساب للدخول.

**المسار الأساسي**
1. `POST /auth/register` → إنشاء مواطن غير مفعّل + OTP (`OtpPurpose::register`).
2. `POST /auth/verify-otp` → تفعيل البريد وإصدار Token (Sanctum).
3. `POST /auth/login` بعد التحقق.
4. اختياري: نسيان كلمة المرور عبر OTP ثم إعادة التعيين.
5. `POST /auth/logout`.

**المسارات البديلة والاستثنائية**  
OTP غير صالح؛ بيانات دخول غير صحيحة؛ تقييد معدل الطلبات على نسيان كلمة المرور.

**نقاط القرار**  
صلاحية OTP؛ صحة بيانات الدخول.

**تغييرات الحالة**  
تفعيل الحساب و`email_verified_at`؛ لا تغيير لـ ApplicationStatus هنا.

**النتيجة النهائية**  
جلسة مواطن مصادَقة أو فشل مصادقة.

**الارتباط بالتنفيذ**  
`RegisterController`, `LoginController`, `ForgotPasswordController`, `AuthService`, `OtpService`, `PasswordResetFlowTest`.

---

### 03 - الملف الشخصي ومراجعته

**الهدف**  
تمييز مراجعة الملف الشخصي عن مراجعة وثائق الطلب.

**الجهات/Swimlanes**  
مواطن، نظام DLMS، مراجع الملف الشخصي.

**الشروط المسبقة**  
مواطن مصادَق.

**المسار الأساسي**
1. `PUT /profile/complete` أو تحديث حساس → `ProfileStatus::pending_review`.
2. المراجع يعتمد عبر `/admin/profile-reviews/{user}/approve` → `approved`.
3. Middleware `profile.approved` يفتح عمليات الخدمات اللاحقة.

**المسارات البديلة والاستثنائية**  
رفض مع `profile_rejection_reason` → `rejected` ثم إعادة الإكمال.

**نقاط القرار**  
اعتماد / رفض الملف.

**تغييرات الحالة**  
`incomplete` → `pending_review` → `approved` | `rejected`.

**النتيجة النهائية**  
ملف معتمد يسمح بإنشاء الطلبات والدفع والمواعيد.

**الارتباط بالتنفيذ**  
`ProfileService`, `ProfileReviewService`, `ProfileApprovalFlowTest`.

---

### 04 - إنشاء طلب رخصة جديدة

**الهدف**  
إنشاء طلب خدمة `new_license` بحالة ابتدائية صحيحة.

**الجهات/Swimlanes**  
مواطن، نظام DLMS.

**الشروط المسبقة**  
`profile.approved`؛ عدم وجود طلب نشط مكرر يمنع الإنشاء حسب قواعد الخدمة.

**المسار الأساسي**
1. اختيار نوع الرخصة ونوع الخدمة.
2. التحقق من الأهلية/التكرار.
3. `POST /applications` → `ApplicationStatus::draft`.
4. الانتقال لخطوة الوثائق.

**المسارات البديلة والاستثنائية**  
ملف غير معتمد؛ طلب مكرر نشط؛ خدمة تتطلب `related_license_id` (ليست مسار الرخصة الجديدة).

**نقاط القرار**  
هل الملف معتمد؛ هل يوجد تكرار مانع.

**تغييرات الحالة**  
إنشاء → `draft`.

**النتيجة النهائية**  
طلب مسودة جاهز لرفع الوثائق.

**الارتباط بالتنفيذ**  
`ApplicationController@store`, `ApplicationService`, `ServiceWorkflow`, `ApplicationFlowTest`.

---

### 05 - رفع ومراجعة الوثائق

**الهدف**  
الفصل بين رفع الوثيقة وإرسال الحزمة للمراجعة، ثم قرار المراجع.

**الجهات/Swimlanes**  
مواطن، نظام DLMS، مراجع الوثائق.

**الشروط المسبقة**  
طلب في `draft` أو `documents_rejected`.

**المسار الأساسي**
1. عرض الوثائق المطلوبة.
2. رفع ملف → `DocumentStatus::pending_review`.
3. `POST .../submit-documents` → `documents_under_review`.
4. اعتماد كل الوثائق المطلوبة → `payment_pending`.

**المسارات البديلة والاستثنائية**  
رفض أي وثيقة → `documents_rejected` مع سبب `DocumentRejectionReason`؛ إعادة الرفع ثم إعادة الإرسال.

**نقاط القرار**  
هل كل الوثائق معتمدة.

**تغييرات الحالة**  
طلب: `draft|documents_rejected` → `documents_under_review` → `payment_pending` | `documents_rejected`  
وثيقة: `pending_review` → `approved` | `rejected`.

**النتيجة النهائية**  
جاهزية الدفع أو إعادة المعالجة بعد الرفض.

**الارتباط بالتنفيذ**  
`ApplicationDocumentService`, `DocumentReviewService`, `DocumentFlowTest`, `DashboardDocumentReviewTest`.

---

### 06 - المسار اليدوي الكامل للمواطن

**الهدف**  
إظهار رحلة المواطن عندما يختار المسار اليدوي دون الوكيل الذكي.

**الجهات/Swimlanes**  
مواطن، نظام DLMS، الموظفون (مراجعة/اختبار/إصدار حسب المرحلة).

**الشروط المسبقة**  
حساب مفعّل وملف معتمد للعمليات المُغيِّرة.

**المسار الأساسي**
1. إنشاء طلب ورفع وإرسال الوثائق.
2. انتظار اعتماد الوثائق.
3. إنشاء دفع وإتمامه.
4. لـ `new_license`: حجز مواعيد وإكمال الاختبارات.
5. انتظار الإصدار ثم استلام الإشعار.

**المسارات البديلة والاستثنائية**  
فشل دفع؛ رفض وثائق؛ رسوب اختبار مع إعادة جدولة.

**نقاط القرار**  
اكتمال الدفع والمراحل التالية.

**تغييرات الحالة**  
نفس سلسلة الحالة في مخطط 17.

**النتيجة النهائية**  
`license_issued` أو توقف مرحلي واضح.

**الارتباط بالتنفيذ**  
واجهات Citizen API في `routes/api.php` دون `/ai-agent/*`.

---

### 07 - المسار عبر الوكيل الذكي

**الهدف**  
نمذجة الوكيل الذكي كـ Swimlane مستقل مع إلزام تأكيد الأفعال المُغيِّرة.

**الجهات/Swimlanes**  
مواطن | الوكيل الذكي | نظام DLMS (إلزامي ومنفصل).

**الشروط المسبقة**  
مواطن مصادَق ضمن مجموعة مسارات المواطن.

**المسار الأساسي**
1. بدء Session وإرسال رسالة `POST /ai-agent/message`.
2. تحليل النية وبناء السياق.
3. إن كان الفعل قراءة فقط → تنفيذ تلقائي عبر خدمات النطاق.
4. إن كان مُغيِّراً → اقتراح فعل بحالة `awaiting_confirmation`.
5. المواطن [تأكيد] → `AIAgentActionService::confirm` → `AgentActionExecutor` → نفس خدمة النطاق المستخدمة يدوياً.
6. أو [إلغاء] → لا mutation.
7. رفع مستندات الجلسة عبر `POST /ai-agent/sessions/{session}/documents` حيث يُدعم.

**المسارات البديلة والاستثنائية**  
حماية من stale/invalid action؛ أفعال إدارية مرفوضة للمواطن؛ أفعال `renew_license` / `request_license_replacement` / `request_unblock` مُدرجة كمُغيِّرة لكنها غير قابلة للتنفيذ بعد في Phase 9B.

**نقاط القرار**  
قراءة فقط مقابل فعل مُغيّر؛ تأكيد مقابل إلغاء.

**تغييرات الحالة**  
`AgentActionStatus`: `awaiting_confirmation` → `confirmed`/`executed` أو `cancelled`.  
حالات الطلب تتغير فقط عبر خدمات النطاق بعد التأكيد.

**النتيجة النهائية**  
نتيجة مقروءة أو تنفيذ مجال ناجح/ملغى بدون أثر.

**الارتباط بالتنفيذ**  
`AIAgentController`, `AIAgentService`, `AgentWorkflowActionMap`, `AgentSafetyRules`, `AIAgentFlowTest` ومجموعة اختبارات Phase.

---

### 08 - الدفع والعمليات المالية

**الهدف**  
تمثيل تدفق الدفع الحقيقي: Mock وStripe وWebhook وتسوية الموظف.

**الجهات/Swimlanes**  
مواطن، نظام DLMS، بوابة الدفع، الموظف المالي.

**الشروط المسبقة**  
الطلب في `payment_pending`.

**المسار الأساسي**
1. إنشاء Payment بحالة `pending`.
2. [Mock] تأكيد عبر `.../payments/{payment}/confirm` أو [Stripe] Checkout ثم `POST /webhooks/stripe`.
3. `PaymentLifecycleService::completeVerifiedPayment` → `payment_completed` ثم فوراً:
   - `appointment_pending` إذا `ServiceWorkflow::requiresTests` (`new_license`)
   - وإلا `approved`.

**المسارات البديلة والاستثنائية**  
`failed`؛ `under_verification`؛ تحقق الموظف عبر `POST /dashboard/payments/{payment}/verify` و`PaymentReconciliationService`.

**نقاط القرار**  
طريقة الدفع؛ نتيجة العملية؛ هل تتطلب اختبارات.

**تغييرات الحالة**  
Payment: `pending` → `completed|failed|under_verification`  
Application: `payment_pending` → `payment_completed` → `appointment_pending|approved`.

**النتيجة النهائية**  
دفع مكتمل مع انتقال الطلب، أو فشل/تحت التحقق.

**الارتباط بالتنفيذ**  
`ApplicationPaymentService`, `PaymentLifecycleService`, `StripeWebhookController`, `DashboardPaymentService`, `PaymentFlowTest`, `PaymentStripeTest`.

---

### 09 - حجز وتعديل وإلغاء الموعد

**الهدف**  
حجز/إعادة جدولة/إلغاء مع قيود السعة والحالة.

**الجهات/Swimlanes**  
مواطن، نظام DLMS.

**الشروط المسبقة**  
خدمة تتطلب اختبارات؛ الطلب في `appointment_pending|in_testing|waiting_retest`.

**المسار الأساسي**
1. جلب الفترات المتاحة.
2. الحجز → Appointment `booked`؛ ومن `appointment_pending` ينتقل الطلب إلى `in_testing`.
3. إعادة الجدولة لموعد `booked`.
4. الإلغاء يحرر السعة ولا يغيّر حالة الطلب تلقائياً.

**المسارات البديلة والاستثنائية**  
فترة غير متاحة؛ تعارض سعة؛ موعد غير قابل للتعديل.

**نقاط القرار**  
توفر الفترة؛ إلغاء مقابل إعادة جدولة.

**تغييرات الحالة**  
Appointment: `booked` → `cancelled` (أو لاحقاً `completed`/`no_show` عند النتيجة)  
Application: `appointment_pending` → `in_testing` عند أول حجز مؤهل.

**النتيجة النهائية**  
موعد محجوز/معدّل/ملغى.

**الارتباط بالتنفيذ**  
`AppointmentService`, `AppointmentFlowTest`, `AppointmentSlotConcurrencyTest`.

---

### 10 - الاختبارات ونتائجها

**الهدف**  
تمثيل الترتيب الفعلي: vision → theory → practical ومحاولات الإعادة.

**الجهات/Swimlanes**  
مواطن، نظام DLMS، الممتحن.

**الشروط المسبقة**  
طلب في مرحلة اختبار؛ موعد محجوز.

**المسار الأساسي**
1. الممتحن يسجل نتيجة الموعد.
2. نجاح مع اختبارات متبقية → يبقى/يعود ضمن مسار الاختبار لنوع التالي.
3. نجاح كل المطلوب → `approved`.
4. رسوب/غياب ضمن الحد → `waiting_retest` ثم إعادة حجز.
5. استنفاد `max_attempts` (3 في البذور) → `administrative_review`.

**المسارات البديلة والاستثنائية**  
`no_show`؛ محاولة حجز نوع غير مسموح حسب التسلسل.

**نقاط القرار**  
نجاح/رسوب؛ اكتمال السلسلة؛ حدود المحاولات.

**تغييرات الحالة**  
`in_testing` / `waiting_retest` / `approved` / `administrative_review`  
نتيجة الاختبار: `passed|failed|no_show`.

**النتيجة النهائية**  
جاهزية الإصدار أو مراجعة إدارية أو إعادة اختبار.

**الارتباط بالتنفيذ**  
`TestResultService`, `TestProgressionService`, `TestTypesSeeder`, اختبارات المواعيد/النتائج.

---

### 11 - إصدار الرخصة

**الهدف**  
إصدار الرخصة بعد تحقق الأهلية النهائية.

**الجهات/Swimlanes**  
موظف الإصدار، نظام DLMS، مواطن.

**الشروط المسبقة**  
غالباً `approved` مع اكتمال الوثائق والدفع وعدم وجود غرامات مانعة وفق `LicenseIssuanceEligibilityService`.

**المسار الأساسي**
1. التحقق النهائي من الأهلية.
2. `POST /admin/applications/{application}/issue-license`.
3. إنشاء سجل الرخصة وتحديث الطلب إلى `license_issued`.
4. إنشاء إشعار للمواطن.

**المسارات البديلة والاستثنائية**  
عدم استيفاء الشروط؛ خدمة غير قابلة للإصدار.

**نقاط القرار**  
هل الحالة معتمدة؛ هل الشروط المالية/الوثائقية مكتملة.

**تغييرات الحالة**  
Application: `approved` → `license_issued`  
License: عادة `active` (مع فروع renew/lost/damaged حسب نوع الخدمة).

**النتيجة النهائية**  
رخصة صادرة ومُشعَر بها.

**الارتباط بالتنفيذ**  
`LicenseService::issueForApplication`, `LicenseIssuanceEligibilityService`, `LicenseFlowTest`.

---

### 12 - التجديد

**الهدف**  
إظهار مساري التجديد الحقيقيين دون نسخ مسار الرخصة الجديدة.

**الجهات/Swimlanes**  
مواطن، نظام DLMS، موظف الإصدار (لمسار الطلب).

**الشروط المسبقة**  
رخصة مرتبطة وأهلية تجديد.

**المسار الأساسي**
- **A طلب تطبيق**: خدمة `renew_license` + `related_license_id` → وثائق → دفع → `approved` (بدون اختبارات) → إصدار.
- **B مباشر**: `POST /licenses/{id}/renew` عبر `LicenseService` (الرخصة القديمة → `renewed`).

**المسارات البديلة والاستثنائية**  
عدم الأهلية؛ فشل دفع في مسار الطلب.

**نقاط القرار**  
مسار الطلب مقابل API مباشر؛ الأهلية.

**تغييرات الحالة**  
مسار الطلب: سلسلة دفع ثم `approved` → `license_issued`  
مسار مباشر: رخصة جديدة `active`؛ القديمة `renewed`.

**النتيجة النهائية**  
رخصة مجددة.

**الارتباط بالتنفيذ**  
`ServiceWorkflow::requiresTests` = false للتجديد؛ `LicenseServiceEligibilityService`؛ مسارات التطبيقات والرخص.

---

### 13 - بدل فاقد أو تالف

**الهدف**  
تمييز `lost` و`damaged` برسوم مختلفة مع مساري تطبيق ومباشر.

**الجهات/Swimlanes**  
مواطن، نظام DLMS، موظف الإصدار.

**الشروط المسبقة**  
رخصة قابلة للاستبدال.

**المسار الأساسي**
1. اختيار [فاقد] أو [تالف] → `lost_replacement_fee` / `damaged_replacement_fee`.
2. مسار طلب: وثائق + دفع → `approved` → إصدار.
3. مسار مباشر: `POST /licenses/{id}/replacement` → رخصة جديدة `active` والقديمة `inactive`.

**المسارات البديلة والاستثنائية**  
رفض أهلية؛ فشل دفع.

**نقاط القرار**  
نوع الاستبدال؛ مسار الخدمة.

**تغييرات الحالة**  
كما في مسار الخدمات بلا اختبارات؛ أو انتقال حالة الرخصة في المسار المباشر.

**النتيجة النهائية**  
رخصة بديلة نشطة.

**الارتباط بالتنفيذ**  
`ServiceCode::LostReplacement|DamagedReplacement`, `LicenseController@replacement`.

---

### 14 - دورة حياة الإشعارات

**الهدف**  
التأكيد أن قاعدة البيانات هي مصدر الحقيقة، وFirebase قناة تسليم فقط.

**الجهات/Swimlanes**  
خدمة النطاق، نظام الإشعارات، Queue، Firebase FCM، جهاز المواطن.

**الشروط المسبقة**  
حدث أعمال يوجب إشعاراً.

**المسار الأساسي**
1. حدث أعمال → `NotificationService` يكتب إشعار DB (مركز الإشعارات).
2. إن كان Push مفعّلاً وجهازاً مسجلاً → تخطيط تسليم → Job → FCM → الجهاز.
3. عند فشل FCM يبقى إشعار DB صالحاً.

**المسارات البديلة والاستثنائية**  
عدم وجود جهاز؛ فشل تسليم (`PushDeliveryStatus::failed|invalid_token`).

**نقاط القرار**  
توفر Push؛ نجاح/فشل FCM.

**تغييرات الحالة**  
سجلات `notifications` و`push_deliveries`؛ دون تغيير حالة الطلب بحد ذاتها.

**النتيجة النهائية**  
إشعار محفوظ (± تسليم Push).

**الارتباط بالتنفيذ**  
`NotificationService`, `PushDeliveryService`, `FcmClient`, `NotificationEventMatrix`, اختبارات Notification/Push/Firebase.

---

### 15 - الموظفون والمراجعات

**الهدف**  
تجميع طوابير عمل لوحة التحكم/الإدارة المؤثرة على حالة المواطن.

**الجهات/Swimlanes**  
مراجع ملف، مراجع وثائق، موظف مالي، ممتحن، موظف إصدار، نظام DLMS.

**الشروط المسبقة**  
موظف مصادَق بصلاحية مناسبة.

**المسار الأساسي**
1. التحقق من الصلاحية.
2. تنفيذ إجراء الطابور (اعتماد ملف/وثيقة، تحقق دفع، تسجيل نتيجة، إصدار).
3. انتقال حالة الطلب/الملف وإشعار المواطن عند الانطباق.

**المسارات البديلة والاستثنائية**  
رفض الصلاحية؛ رفض وثيقة/ملف.

**نقاط القرار**  
مصرح / غير مصرح.

**تغييرات الحالة**  
حسب نوع الإجراء (انظر مخطط 17 والخدمات المقابلة).

**النتيجة النهائية**  
تقدّم أو رفض في مسار المواطن.

**الارتباط بالتنفيذ**  
`app/Modules/Admin/Routes/admin.php`, `app/Modules/Dashboard/Routes/dashboard.php`, صلاحيات `config/dashboard_permissions.php`.

---

### 16 - الإدارة والصلاحيات

**الهدف**  
أنشطة إدارة الموظفين والأدوار والجلسات دون تحويل المخطط إلى Class/ERD.

**الجهات/Swimlanes**  
المدير / Super Admin، نظام DLMS.

**الشروط المسبقة**  
صلاحيات `manage_employees` / `super_admin` / `root_super_admin` حسب المسار.

**المسار الأساسي**
1. إدارة الموظفين.
2. إدارة الأدوار والصلاحيات (Access Control).
3. تتبع/إبطال جلسات الموظفين.
4. فحوصات Authorization عند كل طلب لوحة.

**المسارات البديلة والاستثنائية**  
رفض الإجراء؛ حماية حسابات Super Admin.

**نقاط القرار**  
وجود permission من عدمه.

**تغييرات الحالة**  
`EmployeeSessionStatus` وغيرها من حالات الجلسة؛ لا ApplicationStatus مباشرة.

**النتيجة النهائية**  
تهيئة تحكم وصول فعّالة.

**الارتباط بالتنفيذ**  
`DashboardAccessControlTest`, `EmployeeManagementTest`, `EmployeeSession*`, `RbacBootstrapService`.

---

### 17 - حالات الطلب الرئيسية

**الهدف**  
مخطط دورة حياة حالات `ApplicationStatus` المشتقة من التنفيذ.

**الجهات/Swimlanes**  
نظام DLMS (مع ملاحظات انتقالية).

**الشروط المسبقة**  
وجود طلب.

**المسار الأساسي**
`draft` → `documents_under_review` → `payment_pending` → `payment_completed` → (`appointment_pending` → `in_testing` → …) أو `approved` → `license_issued`.

**المسارات البديلة والاستثنائية**  
`documents_rejected`؛ `waiting_retest`؛ `administrative_review`.  
**ملاحظة تنفيذية:** القيمتان `rejected` و`cancelled` معرفتان في الـ enum ومربوطتان بإشعارات، لكن لا يوجد حالياً caller إنتاجي لـ `transitionStatus` إليهما (`NotificationEventMatrix`: wired_pending_caller).

**نقاط القرار**  
سلامة الوثائق؛ هل الخدمة `new_license`.

**تغييرات الحالة**  
كل الأسماء مطابقة لـ `App\Enums\ApplicationStatus`.

**النتيجة النهائية**  
`license_issued` أو حالة مرحلية/مراجعة إدارية؛ والحالتان الطرفيتان غير المفعّلتين إنتاجياً موثقتان صراحة.

**الارتباط بالتنفيذ**  
`ApplicationRepository::transitionStatus` وجميع مستدعيه؛ اختبارات التدفق الشاملة.

---

## 6. المسار اليدوي مقابل الوكيل الذكي

| البعد | المسار اليدوي | مسار الوكيل الذكي |
|------|----------------|-------------------|
| نقطة الدخول | واجهات Citizen API مباشرة | `/api/ai-agent/*` |
| Swimlane | المواطن ينفّذ | مواطن + **وكيل ذكي منفصل** |
| القراءة | مباشرة | قد تُنفَّذ تلقائياً |
| الأفعال المُغيِّرة | فورية بعد تحقق الطلب | اقتراح → `awaiting_confirmation` → تأكيد/إلغاء |
| التنفيذ النهائي | خدمة النطاق | نفس خدمة النطاق عبر `AgentActionExecutor` |
| الإلغاء | لا ينشأ تنفيذ | Cancel = لا mutation |

الخلاصة: الوكيل ليس خلفيّة ثانية؛ هو واجهة محادثة تلتقي مع منطق المجال ذاته.

---

## 7. دورة حياة الطلب

الانتقالات الإنتاجية الموثقة:

| من | إلى | المُسبِّب |
|----|-----|-----------|
| (إنشاء) | `draft` | إنشاء الطلب |
| `draft` / `documents_rejected` | `documents_under_review` | إرسال الوثائق |
| `documents_under_review` | `documents_rejected` | رفض وثيقة |
| `documents_under_review` | `payment_pending` | اعتماد كل الوثائق المطلوبة |
| `payment_pending` | `payment_completed` ثم `appointment_pending` أو `approved` | اكتمال الدفع الموثق |
| `appointment_pending` | `in_testing` | حجز موعد |
| `in_testing` / `waiting_retest` | `approved` / `in_testing` / `waiting_retest` / `administrative_review` | نتائج الاختبار |
| `approved` | `license_issued` | الإصدار |

غير مفعّل إنتاجياً حتى الآن: الانتقال إلى `rejected` أو `cancelled`.

---

## 8. الإشعارات

- **مصدر الحقيقة**: جدول `notifications` عبر `NotificationService`.
- **قناة تسليم اختيارية**: تسجيل الجهاز → تخطيط Push → Queue → FCM.
- فشل FCM لا يلغي الإشعار المخزَّن.
- لا تُرسم أنبوبة Firebase كاملة في كل مخطط أعمال؛ يُشار بـ «إنشاء إشعار للمواطن» ثم يُفصَّل هنا وفي الصفحة 14.

---

## 9. قواعد العمل المهمة

1. Middleware `profile.approved` شرط لمعظم عمليات المواطن المُغيِّرة.
2. الاختبارات مطلوبة فقط لـ `new_license` (`ServiceWorkflow::requiresTests`).
3. ترتيب الاختبارات: `vision` → `theory` → `practical`؛ `max_attempts = 3` في البذور.
4. رفع الوثيقة ≠ إرسالها للمراجعة.
5. إلغاء الموعد لا يغيّر حالة الطلب تلقائياً.
6. كل أفعال الوكيل المُغيِّرة تتطلب تأكيداً؛ وPhase 9B يحدد ما يُنفَّذ فعلاً بعد التأكيد.
7. مسارات تجديد/بدل مباشرة موجودة بجانب مسار الطلب.
8. Stripe webhook مُنفَّذ؛ ومزوّد Mock أيضاً عبر الإعدادات.
9. OTP مُنفَّذ للتسجيل ونسيان كلمة المرور (مواطن ولوحة حيث ينطبق).

---

## 10. مصفوفة التتبّع Traceability Matrix

| Diagram | Workflow | Main Routes | Main Services | Main Tests |
|---------|----------|-------------|---------------|------------|
| 01 Overview | رحلة شاملة | `/auth/*`, `/applications/*`, `/ai-agent/*` | خدمات المجال مجتمعة | Application/Payment/License/AI suites |
| 02 Auth | تسجيل ودخول | `/auth/register`, `/verify-otp`, `/login`, forgot/reset | `AuthService`, `OtpService` | `PasswordResetFlowTest` |
| 03 Profile | ملف ومراجعة | `/profile/*`, `/admin/profile-reviews/*` | `ProfileService`, `ProfileReviewService` | `ProfileApprovalFlowTest` |
| 04 Create App | إنشاء طلب جديد | `POST /applications` | `ApplicationService`, `ServiceWorkflow` | `ApplicationFlowTest` |
| 05 Documents | رفع ومراجعة وثائق | `/applications/{id}/documents`, `submit-documents`, `/admin/documents/*` | `ApplicationDocumentService`, `DocumentReviewService` | `DocumentFlowTest` |
| 06 Manual | مسار مواطن يدوي | Citizen APIs | نفس خدمات المجال | Application/Appointment/Payment flows |
| 07 AI Agent | وكيل ذكي | `/ai-agent/message`, `actions/{id}/confirm|cancel`, `sessions/.../documents` | `AIAgentService`, `AgentActionExecutor` | `AIAgentFlowTest`, Phase tests |
| 08 Payment | دفع | `/applications/{id}/payments`, `/webhooks/stripe`, `/dashboard/payments/{id}/verify` | `PaymentLifecycleService`, Stripe/Mock | `PaymentFlowTest`, `PaymentStripeTest` |
| 09 Appointment | مواعيد | `/appointments`, reschedule/cancel, slots | `AppointmentService` | `AppointmentFlowTest` |
| 10 Tests | نتائج اختبار | admin record-result | `TestResultService`, `TestProgressionService` | Appointment/Tests feature tests |
| 11 Issuance | إصدار | `/admin/applications/{id}/issue-license` | `LicenseService` | `LicenseFlowTest` |
| 12 Renewal | تجديد | app `renew_license` + `POST /licenses/{id}/renew` | `LicenseService`, Eligibility | License feature tests |
| 13 Replacement | بدل فاقد/تالف | app lost/damaged + `POST /licenses/{id}/replacement` | `LicenseService` | License feature tests |
| 14 Notifications | إشعار وPush | `/notifications/*`, `/devices/push-token` | `NotificationService`, `PushDeliveryService` | Notification/Push/Firebase tests |
| 15 Employees | طوابير موظفين | `/admin/*`, `/dashboard/*` | Admin/Dashboard services | Dashboard document/payment tests |
| 16 Admin RBAC | صلاحيات | `/dashboard/employees`, `/access-control`, sessions | RBAC/Session services | AccessControl/EmployeeSession tests |
| 17 Lifecycle | حالات الطلب | عبر خدمات الانتقال | `ApplicationRepository::transitionStatus` | Application lifecycle tests |

---

## 11. الملاحظات والفروقات

1. **لا توجد حزمة Activity Diagrams قديمة** في المستودع؛ الحزمة الحالية هي المرجع الكانوني الجديد. ملف ERD منفصل لم يُستبدل.
2. **`rejected` / `cancelled`**: موجودتان في Enum وإشعارات، بلا انتقال إنتاجي حالياً — وُضحتا في الصفحة 17 كتقييد تنفيذي.
3. **أفعال تجديد/بدل/فك حظر عبر الوكيل**: مُدرجة كمُغيِّرة تتطلب تأكيداً، لكن Phase 9B لا ينفّذها بعد.
4. **مساران لكل من التجديد والبدل**: طلب تطبيق مقابل API رخصة مباشر — كلاهما منفَّذ ويجب عدم دمجهما كمفهوم واحد.
5. **طلب فك الحظر المباشر** (`unblock-request`) يرسل رسالة/طلباً دون تغيير حالة الرخصة مباشرة في ذلك المسار المختصر.
6. **لا يُمثَّل RAG**؛ لا يظهر في التنفيذ الحالي للوكيل.
7. أي تعارض بين وثائق تصميم قديمة والتنفيذ الحالي يُحسم لصالح التنفيذ والاختبارات.

---

## 12. الخلاصة

توفر الحزمة 17 صفحة Activity Diagram قابلة للتحرير تغطي المسارات الرئيسية المنفَّذة في SYRTAK/DLMS، مع Swimlanes صحيحة، وفصل صريح للوكيل الذكي، وتأكيد الأفعال المُغيِّرة، وحالات آلة مطابقة للكود، وتوثيق صريح للقيود والفجوات (مثل `rejected`/`cancelled` غير المفعّلتين إنتاجياً). التقرير العربي يكمل التتبّع إلى المسارات والخدمات والاختبارات دون تعديل أي كود تطبيقي.

### جرد تغطية سريع

| Workflow | التصنيف |
|----------|---------|
| Authentication | COVERED |
| Profile / Profile Review | COVERED |
| Application Creation | COVERED |
| Documents Upload / Review | COVERED |
| Payment (+ Stripe webhook + Mock) | COVERED |
| Appointment book/reschedule/cancel | COVERED |
| Tests / Retest | COVERED |
| License Issuance | COVERED |
| Renewal | COVERED |
| Lost/Damaged Replacement | COVERED |
| Notification Center + Firebase Push | COVERED |
| AI Agent | COVERED |
| Employee Review Operations | COVERED |
| RBAC/Admin | COVERED |
| Application `rejected`/`cancelled` transitions | NOT IMPLEMENTED (موثّق كفجوة) |
| AI renew/replace/unblock execution | PARTIAL (اقتراح/تأكيد دون تنفيذ Phase 9B) |

---

*Generated for SYRTAK / DLMS — Activity Diagrams package — source of truth: current backend implementation and automated tests.*
