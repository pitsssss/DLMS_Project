<?php

namespace App\Modules\AIAgent\Support;

use App\Modules\AIAgent\Services\AgentLocaleContext;
use Illuminate\Support\Facades\Lang;

/**
 * AI Agent message translator.
 * 
 * IMPORTANT: This class no longer uses static mutable state for locale.
 * Locale is now managed by AgentLocaleContext (request-scoped service).
 */
class AgentTranslator
{
    /**
     * Resolve assistant reply text; never return raw translation keys to the client.
     */
    public static function resolveReply(string $reply, array $replace = []): string
    {
        $trimmed = trim($reply);

        if ($trimmed === '') {
            return $trimmed;
        }

        if (str_starts_with($trimmed, 'messages.')) {
            return self::message(substr($trimmed, strlen('messages.')), $replace);
        }

        return $trimmed;
    }

    public static function message(string $key, array $replace = [], ?string $locale = null): string
    {
        $fullKey = str_starts_with($key, 'messages.') ? $key : 'messages.'.$key;
        $locale = $locale ?? self::getLocale();
        if (! in_array($locale, ['ar', 'en'], true)) {
            $locale = AgentLocaleContext::getDefaultLocale();
        }

        // Do not use Laravel's locale fallback chain — it would return Arabic
        // strings when an English lang file key is missing.
        if (Lang::has($fullKey, $locale, false)) {
            $translated = Lang::get($fullKey, $replace, $locale);

            if (is_string($translated) && $translated !== $fullKey) {
                return $translated;
            }
        }

        return self::fallbackForKey($fullKey, $replace, $locale);
    }

    /**
     * Get the current locale from the request-scoped context.
     * 
     * Safe fallback for tests and edge cases where context is not available.
     */
    public static function getLocale(): string
    {
        if (!app()->bound(AgentLocaleContext::class)) {
            return AgentLocaleContext::getDefaultLocale();
        }

        try {
            /** @var AgentLocaleContext $context */
            $context = app(AgentLocaleContext::class);
            return $context->getLocale();
        } catch (\Throwable) {
            // Fallback to Arabic if context is not available (e.g., in tests without proper setup)
            return AgentLocaleContext::getDefaultLocale();
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function localizePayload(array $payload): array
    {
        if (isset($payload['reply']) && is_string($payload['reply'])) {
            $payload['reply'] = self::resolveReply($payload['reply']);
        }

        if (isset($payload['result']) && is_array($payload['result'])) {
            $payload['result'] = self::localizeArray($payload['result']);
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function localizeArray(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $trimmed = trim($value);
                if (str_starts_with($trimmed, 'messages.')) {
                    $data[$key] = self::resolveReply($trimmed);
                }
            } elseif (is_array($value)) {
                $data[$key] = self::localizeArray($value);
            }
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $replace
     */
    private static function fallbackForKey(string $fullKey, array $replace, ?string $locale = null): string
    {
        $suffix = str_replace('messages.', '', $fullKey);
        $locale = $locale ?? self::getLocale();
        if (! in_array($locale, ['ar', 'en'], true)) {
            $locale = AgentLocaleContext::getDefaultLocale();
        }

        if (str_starts_with($suffix, 'ai_agent.application_next_step.')) {
            $status = substr($suffix, strlen('ai_agent.application_next_step.'));

            return self::applicationNextStepFallback($status, $replace, $locale);
        }

        if (str_starts_with($suffix, 'ai_agent.workflow.no_application.')) {
            $key = substr($suffix, strlen('ai_agent.workflow.no_application.'));

            return $locale === 'en' 
                ? self::noApplicationFallbackEn($key)
                : self::noApplicationFallbackAr($key);
        }

        if (str_starts_with($suffix, 'ai_agent.workflow.multiple_applications.')) {
            $key = substr($suffix, strlen('ai_agent.workflow.multiple_applications.'));

            return $locale === 'en'
                ? self::multipleApplicationsFallbackEn($key, $replace)
                : self::multipleApplicationsFallbackAr($key, $replace);
        }

        return $locale === 'en' 
            ? self::generalFallbackEn($suffix, $replace)
            : self::generalFallbackAr($suffix, $replace);
    }

    private static function noApplicationFallbackAr(string $key): string
    {
        return match ($key) {
            'payment' => 'لا يوجد لديك طلب حالي متعلق بالدفع. يمكنني مساعدتك في متابعة طلب موجود أو إنشاء طلب جديد.',
            'appointment' => 'لا يوجد لديك طلب حالي متعلق بالمواعيد. يمكنني مساعدتك في متابعة طلب موجود أو إنشاء طلب جديد.',
            'test_results' => 'لا يوجد لديك طلب حالي لعرض نتائج الاختبارات.',
            default => 'لا يوجد لديك طلب حالي لهذه العملية.',
        };
    }

    private static function noApplicationFallbackEn(string $key): string
    {
        return match ($key) {
            'payment' => 'You have no current application related to payment. I can help you track an existing application or create a new one.',
            'appointment' => 'You have no current application related to appointments. I can help you track an existing application or create a new one.',
            'test_results' => 'You have no current application to view test results.',
            default => 'You have no current application for this operation.',
        };
    }

    /**
     * @param  array<string, mixed>  $replace
     */
    private static function multipleApplicationsFallbackAr(string $key, array $replace): string
    {
        return match ($key) {
            'payment' => 'لديك أكثر من طلب قيد المتابعة. من فضلك حدد رقم الطلب الذي تريد معرفة رسومه أو دفعه.'
                .(isset($replace['summary']) ? "\n".$replace['summary'] : ''),
            'appointment' => 'لديك أكثر من طلب قيد المتابعة. من فضلك حدد رقم الطلب الذي تريد حجز موعد له.'
                .(isset($replace['summary']) ? "\n".$replace['summary'] : ''),
            'test_results' => 'لديك أكثر من طلب قيد المتابعة. من فضلك حدد رقم الطلب الذي تريد معرفة نتائج اختباراته.'
                .(isset($replace['summary']) ? "\n".$replace['summary'] : ''),
            default => 'لديك أكثر من طلب قيد المتابعة. من فضلك حدد الطلب المطلوب.',
        };
    }

    /**
     * @param  array<string, mixed>  $replace
     */
    private static function multipleApplicationsFallbackEn(string $key, array $replace): string
    {
        return match ($key) {
            'payment' => 'You have more than one application in progress. Please specify the application number for which you want to know the fees or make a payment.'
                .(isset($replace['summary']) ? "\n".$replace['summary'] : ''),
            'appointment' => 'You have more than one application in progress. Please specify the application number for which you want to book an appointment.'
                .(isset($replace['summary']) ? "\n".$replace['summary'] : ''),
            'test_results' => 'You have more than one application in progress. Please specify the application number for which you want to see test results.'
                .(isset($replace['summary']) ? "\n".$replace['summary'] : ''),
            default => 'You have more than one application in progress. Please specify the required application.',
        };
    }

    /**
     * @param  array<string, mixed>  $replace
     */
    private static function generalFallbackAr(string $suffix, array $replace): string
    {
        return match ($suffix) {
            'ai_agent.existing_active_application' => 'لديك طلب رخصة قيادة '
                .($replace['label'] ?? 'قيد المتابعة')
                .' قيد المتابعة بالفعل. يمكنك متابعة الطلب الحالي بدلاً من إنشاء طلب جديد.',
            'ai_agent.no_active_applications' => 'لا يوجد لديك طلبات حالية لعرض حالتها.',
            'ai_agent.multiple_active_applications' => 'لديك أكثر من طلب قيد المتابعة. أي طلب تريد معرفة حالته؟'
                .(isset($replace['summary']) ? "\n".$replace['summary'] : ''),
            'ai_agent.status_prompt_confirm' => 'وجدت طلباً قيد المتابعة. هل تريد عرض حالته؟',
            'ai_agent.application_status.with_next_step' => 'حالة الطلب '
                .($replace['number'] ?? '')
                .' هي: '
                .($replace['status'] ?? '')
                .'. '
                .($replace['next_step'] ?? ''),
            'ai_agent.required_documents.no_applications' => 'لا يوجد لديك طلب حالي لعرض الوثائق المطلوبة. يمكنني مساعدتك في إنشاء طلب جديد إذا أردت.',
            'ai_agent.required_documents.multiple_applications' => 'لديك أكثر من طلب قيد المتابعة. من فضلك حدد رقم الطلب الذي تريد معرفة وثائقه المطلوبة.'
                .(isset($replace['summary']) ? "\n".$replace['summary'] : ''),
            'ai_agent.required_documents.list' => 'الوثائق المطلوبة لطلبك هي: '
                .($replace['documents'] ?? '').'.',
            'ai_agent.required_documents.already_uploaded_hint' => 'بعض الوثائق مرفوعة مسبقاً، ويمكنك متابعة حالة كل وثيقة من صفحة وثائق الطلب.',
            'ai_agent.required_documents.stage_completed_hint' => 'تمت مراجعة وثائق هذا الطلب أو تجاوز مرحلة الوثائق. يمكنك متابعة الخطوة الحالية من حالة الطلب.',
            'ai_agent.required_documents.unavailable' => 'لم أتمكن من جلب الوثائق المطلوبة لهذا الطلب حالياً.',
            'ai_agent.appointments.current.single' => 'نعم، تم حجز موعد لاختبار '
                .($replace['test'] ?? 'الاختبار')
                .' بتاريخ '
                .($replace['date'] ?? '')
                .' الساعة '
                .($replace['time'] ?? '')
                .'.',
            'ai_agent.appointments.current.multiple' => 'لديك أكثر من موعد مرتبط بهذا الطلب.',
            'ai_agent.appointments.current.line' => '- '
                .($replace['test'] ?? 'الاختبار')
                .' بتاريخ '
                .($replace['date'] ?? '')
                .' الساعة '
                .($replace['time'] ?? ''),
            'ai_agent.appointments.current.none' => 'لا يوجد لديك موعد محجوز حالياً لهذا الطلب. يمكنك عرض المواعيد المتاحة وحجز موعد مناسب.',
            'ai_agent.appointments.current.no_application' => 'لا يوجد لديك طلب حالي لعرض موعده.',
            'ai_agent.appointments.current.choose_application' => 'لديك أكثر من طلب قيد المتابعة. من فضلك حدد رقم الطلب الذي تريد عرض موعده.'
                .(isset($replace['summary']) && $replace['summary'] !== '' ? "\n".$replace['summary'] : ''),
            'ai_agent.appointments.slots.choose' => 'هذه المواعيد المتاحة. يرجى اختيار الموعد المناسب.',
            'ai_agent.appointments.slots.choose_for_test' => 'هذه هي المواعيد المتاحة لـ'
                .($replace['test'] ?? 'الاختبار')
                .'. اختر الموعد المناسب من القائمة.',
            'ai_agent.appointments.slots.loading_for_test' => 'سأعرض لك المواعيد المتاحة لـ'
                .($replace['test'] ?? 'الاختبار')
                .'.',
            'ai_agent.appointments.slots.none' => 'لا توجد مواعيد متاحة حالياً. يرجى المحاولة لاحقاً.',
            'ai_agent.appointments.slots.none_for_test' => 'لا توجد مواعيد متاحة حالياً لـ'
                .($replace['test'] ?? 'الاختبار')
                .'. يرجى المحاولة لاحقاً.',
            'ai_agent.appointments.book.first_available_confirm' => 'يمكنني حجز أول موعد متاح لـ'
                .($replace['test'] ?? 'الاختبار')
                .' بتاريخ '
                .($replace['date'] ?? '')
                .' الساعة '
                .($replace['time'] ?? '')
                .'. هل تريد تأكيد الحجز؟',
            'ai_agent.appointments.slots.stale' => 'الموعد المحدد لم يعد متاحاً. يرجى اختيار موعد آخر.',
            'ai_agent.appointments.slots.invalid_choice' => 'الخيار المحدد غير ضمن المواعيد المعروضة.',
            'ai_agent.appointments.slots.error_application' => 'تعذر تحديد الطلب لحجز الموعد.',
            'ai_agent.appointments.choose.select' => 'يرجى اختيار الموعد المطلوب.',
            'ai_agent.appointments.choose.none' => 'لا يوجد لديك موعد محجوز حالياً لهذه العملية.',
            'ai_agent.appointments.choose.not_found' => 'الموعد غير موجود أو لا تملك صلاحية الوصول إليه.',
            'ai_agent.appointments.choose.invalid' => 'الخيار المحدد غير ضمن المواعيد المعروضة.',
            'ai_agent.appointments.confirm.prompt' => 'هل تؤكد المتابعة على الموعد المحدد؟',
            'ai_agent.appointments.cancel.confirm_prompt' => 'هل تؤكد إلغاء هذا الموعد؟',
            'ai_agent.appointments.book.vague' => 'يمكنني عرض المواعيد المتاحة. اختر الموعد المناسب ثم أكّد الحجز.',
            'ai_agent.appointments.book.success' => 'تم حجز موعد لاختبار '
                .($replace['test'] ?? 'الاختبار')
                .' بتاريخ '
                .($replace['date'] ?? '')
                .' الساعة '
                .($replace['time'] ?? '')
                .'.',
            'ai_agent.appointments.book.success_short' => 'تم حجز موعد '
                .($replace['test'] ?? 'الاختبار')
                .' بنجاح.',
            'ai_agent.appointments.reschedule.success' => 'تم تغيير موعد اختبار '
                .($replace['test'] ?? 'الاختبار')
                .' إلى '
                .($replace['date'] ?? '')
                .' الساعة '
                .($replace['time'] ?? '')
                .'.',
            'ai_agent.appointments.cancel.success' => 'تم إلغاء موعد '
                .($replace['test'] ?? 'الاختبار')
                .' بنجاح.',
            'ai_agent.appointments.test_fallback' => 'الاختبار',
            'ai_agent.other_license.none_eligible' => 'لا يوجد لديك رخصة يمكن تنفيذ هذه الخدمة عليها حالياً.',
            'ai_agent.other_license.choose' => 'لديك أكثر من رخصة. يرجى اختيار الرخصة المطلوبة.',
            'ai_agent.other_license.invalid_choice' => 'الخيار المحدد غير ضمن الرخص المعروضة.',
            'ai_agent.other_license.confirm' => 'وجدت لديك رخصة قيادة '
                .($replace['label'] ?? '')
                .' رقم '
                .($replace['number'] ?? '')
                .'. هل تريد إنشاء طلب '
                .($replace['service'] ?? 'الخدمة')
                .' لها؟',
            'ai_agent.other_license.service.renew_license' => 'تجديد',
            'ai_agent.other_license.service.lost_replacement' => 'بدل فاقد',
            'ai_agent.other_license.service.damaged_replacement' => 'بدل تالف',
            'ai_agent.payment.start.confirm' => 'يمكنني تجهيز دفع رسوم الطلب. هل تؤكد المتابعة؟',
            'ai_agent.payment.fee.reply' => 'رسوم طلبك '
                .($replace['number'] ?? '')
                .' هي '
                .($replace['amount'] ?? '')
                .' '
                .($replace['currency'] ?? '')
                .'. يمكنك المتابعة للدفع عندما تكون جاهزاً.',
            'ai_agent.payment.fee.unavailable' => 'لم أتمكن من جلب رسوم هذا الطلب حالياً.',
            'ai_agent.payment.status.loading' => 'سأعرض لك حالة الدفع لهذا الطلب.',
            'ai_agent.payment.status.paid' => 'تم دفع رسوم الطلب '
                .($replace['number'] ?? '')
                .' بنجاح.',
            'ai_agent.payment.status.pending' => 'الطلب '
                .($replace['number'] ?? '')
                .' ما زال بانتظار الدفع.',
            'ai_agent.payment.status.unknown' => 'هذه هي حالة الدفع الحالية لطلبك.',
            'ai_agent.licenses.loading' => 'سأعرض لك رخص القيادة الخاصة بك.',
            'ai_agent.fines.loading' => 'سأعرض لك مخالفاتك الحالية.',
            'ai_agent.fines.pay_unsupported' => 'دفع المخالفات عبر المساعد غير متاح حالياً. يمكنك مراجعة المخالفات من التطبيق.',
            'ai_agent.tests.loading' => 'سأعرض لك الاختبارات المتاحة لطلبك مع حالة كل اختبار.',
            'ai_agent.tests.none' => 'لا يوجد اختبار متاح للحجز حالياً. يرجى متابعة الخطوة الحالية لطلبك.',
            'ai_agent.tests.unavailable' => 'لم أتمكن من جلب الاختبارات المتاحة لهذا الطلب حالياً.',
            'ai_agent.tests.single_available' => 'الفحص المتاح حالياً هو '
                .($replace['name'] ?? '')
                .'.',
            'ai_agent.tests.multiple_available' => 'الاختبارات المتاحة حالياً هي: '
                .($replace['names'] ?? '')
                .'.',
            'ai_agent.application_status.simple' => 'حالة الطلب '
                .($replace['number'] ?? '')
                .' هي: '
                .($replace['status'] ?? '')
                .'.',
            'ai_agent.test_results.empty' => 'لا توجد نتائج اختبار مسجلة لهذا الطلب حالياً.',
            'ai_agent.test_results.header' => 'هذه نتائج الاختبارات المسجلة لديك:',
            'ai_agent.test_results.test_fallback' => 'الاختبار',
            'ai_agent.test_results.attempt' => ' (المحاولة '.($replace['attempt'] ?? '').')',
            'ai_agent.test_results.on_date' => ' بتاريخ '.($replace['date'] ?? ''),
            'ai_agent.test_results.line' => '- '
                .($replace['test'] ?? '')
                .': '
                .($replace['result'] ?? '')
                .($replace['attempt_part'] ?? '')
                .($replace['date_part'] ?? '')
                .'.',
            'ai_agent.test_results.result.passed' => 'ناجح',
            'ai_agent.test_results.result.failed' => 'راسب',
            'ai_agent.test_results.result.no_show' => 'غائب',
            'ai_agent.document_flow.selection_required' => 'يرجى اختيار رفع الوثائق عبر المساعد أو الرفع اليدوي.',
            'ai_agent.document_flow.no_eligible_application' => 'لا يوجد لديك حاليًا طلب يسمح برفع الوثائق.',
            'ai_agent.document_flow.multiple_applications' => 'لديك أكثر من طلب يحتاج إلى استكمال الوثائق. يرجى اختيار الطلب المطلوب.',
            'ai_agent.document_flow.application_option' => ($replace['service'] ?? 'طلب')
                .' — رخصة '
                .($replace['license'] ?? '')
                .' — رقم '
                .($replace['id'] ?? ''),
            'ai_agent.document_flow.service_fallback' => 'طلب',
            'ai_agent.document_flow.unknown_interaction' => 'إجراء التفاعل غير معروف.',
            'ai_agent.document_flow.file_required' => 'يرجى إرفاق ملف الوثيقة المطلوبة.',
            'ai_agent.document_flow.multiple_files_with_label' => 'تم إرفاق أكثر من ملف. لقد اخترت رفع وثيقة «'
                .($replace['label'] ?? 'الوثيقة')
                .'»، لذا يرجى إرفاق ملف واحد فقط يخص هذه الوثيقة.',
            'ai_agent.document_flow.multiple_files_reply' => 'تم إرفاق أكثر من ملف، بينما تم اختيار وثيقة «'
                .($replace['label'] ?? 'الوثيقة')
                .'». يرجى إرفاق ملف واحد فقط يخص الوثيقة المختارة، ثم إعادة المحاولة.',
            'ai_agent.document_flow.multiple_files_simple' => 'تم إرفاق أكثر من ملف. يرجى إرفاق ملف واحد فقط.',
            'ai_agent.document_flow.upload_token_app_mismatch' => 'تعارض بين رمز الرفع ومعرّف الطلب المرسل.',
            'ai_agent.document_flow.upload_token_doc_mismatch' => 'تعارض بين رمز الرفع ومعرّف الوثيقة المرسل.',
            'ai_agent.document_flow.upload_failed' => 'تعذر رفع الوثيقة.',
            'ai_agent.document_flow.upload_state_conflict' => 'تعذر إنهاء معالجة رفع الوثيقة بسبب تعارض في الحالة.',
            'ai_agent.document_flow.document_fallback' => 'الوثيقة',
            'ai_agent.document_flow.uploaded_remaining' => 'تم رفع وثيقة «'
                .($replace['label'] ?? 'الوثيقة')
                .'» بنجاح. بقيت الوثائق التالية: '
                .($replace['remaining'] ?? '')
                .'. يرجى اختيار الوثيقة التالية.',
            'ai_agent.document_flow.uploaded_complete' => 'تم رفع وثيقة «'
                .($replace['label'] ?? 'الوثيقة')
                .'» بنجاح. جميع الوثائق المطلوبة مكتملة.',
            'ai_agent.document_flow.already_submitted' => 'تم إرسال الوثائق للمراجعة مسبقًا.',
            'ai_agent.document_flow.consent_confirmation_message' => 'موافقة صريحة سابقة ضمن مسار رفع الوثائق عبر المساعد.',
            'ai_agent.document_flow.submitted_for_review' => 'تم رفع جميع الوثائق المطلوبة وإرسالها إلى قسم مراجعة الوثائق بنجاح. أصبحت حالة طلبك الآن «الوثائق قيد المراجعة». يرجى انتظار نتيجة المراجعة، وسيتم إشعارك عند تحديث حالة الطلب.',
            'ai_agent.document_flow.submission_failed_after_upload' => 'تم رفع جميع الوثائق، لكن تعذّر إرسالها للمراجعة بسبب تغيّر حالة الطلب. يرجى تحديث حالة الطلب والمحاولة مجددًا.',
            'ai_agent.document_flow.upload_offer' => 'الوثائق المطلوبة لاستكمال طلبك هي: '
                .($replace['documents'] ?? '')
                .".\n\n"
                .'هل ترغب في رفع هذه الوثائق من خلال المساعد؟ عند اكتمال رفع جميع الوثائق، سأقوم بإرسالها مباشرةً إلى قسم مراجعة الوثائق.',
            'ai_agent.document_flow.button_agent_upload' => 'نعم، رفعها وإرسالها عبر المساعد',
            'ai_agent.document_flow.button_manual_upload' => 'لا، سأرفعها يدويًا',
            'ai_agent.document_flow.cannot_confirm_agent_upload' => 'لا يمكن تأكيد رفع الوثائق عبر المساعد في هذه المرحلة.',
            'ai_agent.document_flow.no_documents_to_upload' => 'لا توجد وثائق تحتاج إلى رفع حاليًا لهذا الطلب.',
            'ai_agent.document_flow.choose_document' => 'يرجى اختيار الوثيقة التي ترغب في رفعها الآن.',
            'ai_agent.document_flow.cannot_choose_manual' => 'لا يمكن اختيار الرفع اليدوي في هذه المرحلة.',
            'ai_agent.document_flow.manual_guidance' => 'حسنًا. يمكنك رفع الوثائق يدويًا من صفحة وثائق الطلب. انتقل إلى قائمة طلباتك، اختر الطلب المطلوب، ثم افتح قسم الوثائق وارفع الملفات المطلوبة.',
            'ai_agent.document_flow.button_go_to_documents' => 'الانتقال إلى صفحة الوثائق',
            'ai_agent.document_flow.application_selection_unexpected' => 'اختيار الطلب غير متوقع في هذه المرحلة.',
            'ai_agent.document_flow.application_not_eligible' => 'الطلب المحدد لم يعد يسمح برفع الوثائق.',
            'ai_agent.document_flow.document_selection_unexpected' => 'اختيار الوثيقة غير متوقع في هذه المرحلة.',
            'ai_agent.document_flow.selection_token_app_mismatch' => 'رمز اختيار الوثيقة لا يطابق الطلب المرتبط بالجلسة.',
            'ai_agent.document_flow.selection_token_invalid' => 'رمز اختيار الوثيقة غير صالح.',
            'ai_agent.document_flow.required_document_missing' => 'الوثيقة المطلوبة غير موجودة.',
            'ai_agent.document_flow.document_not_required' => 'هذه الوثيقة غير مطلوبة لهذا الطلب.',
            'ai_agent.document_flow.cannot_replace_approved' => 'لا يمكن استبدال وثيقة معتمدة.',
            'ai_agent.document_flow.awaiting_file' => 'يرجى الآن رفع ملف وثيقة «'
                .($replace['label'] ?? 'الوثيقة')
                .'». تأكد من إرفاق ملف واحد فقط يخص هذه الوثيقة تحديدًا، وألا ترفق وثيقة أخرى بدلًا منها.',
            'ai_agent.document_flow.cancelled' => 'تم إلغاء مسار رفع الوثائق عبر المساعد. يمكنك طلب الوثائق المطلوبة في أي وقت.',
            'ai_agent.document_flow.choose_application_first' => 'يرجى اختيار الطلب أولًا.',
            'ai_agent.document_flow.session_application_not_eligible' => 'الطلب المرتبط بالجلسة لم يعد يسمح برفع الوثائق.',
            'ai_agent.document_flow.reupload_hint' => 'يرجى إعادة رفع الوثيقة',
            'ai_agent.document_flow.names_unspecified' => 'غير محددة',
            'ai_agent.document_flow.session_closed' => 'جلسة المساعد الذكي مغلقة.',
            'ai_agent.document_upload.complete_can_submit' => 'تم رفع الوثيقة. جميع الوثائق المطلوبة مكتملة، ويمكنك الآن إرسالها للمراجعة عبر رسالة «أرسل الوثائق للمراجعة».',
            'ai_agent.document_upload.missing_remaining' => 'تم رفع الوثيقة. ما زال هناك وثائق ناقصة لإرسال الطلب للمراجعة: '
                .($replace['names'] ?? '')
                .'.',
            'ai_agent.document_upload.rejected_remaining' => 'تم رفع الوثيقة. ما زالت هناك وثائق مرفوضة لإرسال الطلب للمراجعة: '
                .($replace['names'] ?? '')
                .'. يرجى إعادة رفعها.',
            'ai_agent.document_upload.success' => 'تم رفع الوثيقة بنجاح.',
            'ai_agent.document_status.pending_review' => 'بانتظار المراجعة',
            'ai_agent.document_status.approved' => 'مقبول',
            'ai_agent.document_status.rejected' => 'مرفوض',
            'ai_agent.pending.expired' => 'انتهت صلاحية عملية اختيار الطلب. يرجى إعادة طلب الخدمة.',
            'ai_agent.pending.not_found' => 'لا توجد عملية اختيار طلب قيد الانتظار.',
            'ai_agent.pending.state_invalid' => 'حالة عملية اختيار الطلب غير صالحة لهذا الإجراء.',
            'ai_agent.pending.retry_required' => 'تعذر استكمال العملية مؤقتًا. يرجى إعادة اختيار الطلب.',
            'ai_agent.pending.appointment_choice_missing' => 'لا توجد عملية اختيار موعد قيد الانتظار.',
            'ai_agent.pending.license_choice_missing' => 'لا توجد عملية اختيار رخصة قيد الانتظار.',
            'ai_agent.selection.ambiguous' => 'وجدت أكثر من طلب مطابق. يرجى اختيار الطلب المقصود بدقة.',
            'ai_agent.selection.unresolved' => 'لم أتمكن من تحديد الطلب المقصود. يرجى اختيار أحد الطلبات المعروضة.',
            'ai_agent.selection.path_unavailable' => 'اختيار الطلب غير متاح عبر هذا المسار حاليًا.',
            'ai_agent.selection.no_longer_available' => 'لم تعد الطلبات المعروضة متاحة. يرجى إعادة طلب الخدمة.',
            'ai_agent.selection.prompt' => 'يرجى اختيار أحد الطلبات المعروضة.',
            'ai_agent.selection.invalid' => 'الطلب المحدد غير موجود ضمن الخيارات المعروضة.',
            'ai_agent.selection.no_longer_eligible' => 'الطلب المحدد لم يعد مؤهلاً لهذه العملية.',
            'ai_agent.selection.multiple_prompt' => 'لديك أكثر من طلب قيد المتابعة. يرجى اختيار الطلب المطلوب.',
            'ai_agent.selection.confirm_continue' => 'تم تحديد الطلب. هل تؤكد المتابعة؟',
            'ai_agent.selection.cancelled' => 'تم إلغاء عملية اختيار الطلب. يمكنك طلب أي خدمة أخرى.',
            'ai_agent.selection.subtitle' => 'رخصة '
                .($replace['license'] ?? '')
                .' — '
                .($replace['status'] ?? ''),
            'ai_agent.selection.application_summary_line' => '- '
                .($replace['number'] ?? '')
                .' — رخصة قيادة '
                .($replace['license'] ?? '')
                .' — '
                .($replace['status'] ?? ''),
            'ai_agent.selection.appointment_token_invalid' => 'رمز اختيار الموعد غير صالح.',
            'ai_agent.selection.license_token_invalid' => 'رمز اختيار الرخصة غير صالح.',
            'ai_agent.application.not_owned' => 'الطلب غير موجود أو لا تملك صلاحية الوصول إليه.',
            'ai_agent.action.arguments_incomplete' => 'لا يمكن المتابعة قبل اكتمال البيانات المطلوبة.',
            'ai_agent.existing_active_application_for_license' => 'يوجد لديك طلب فعال مسبقاً لنفس الرخصة ونفس الخدمة. يمكنك متابعة الطلب الحالي بدلاً من إنشاء طلب جديد.',
            'ai_agent.policy.tests_not_required' => 'هذه الخدمة لا تتطلب حجز اختبارات. الخطوة الحالية هي متابعة الوثائق والدفع حتى إصدار الرخصة.',
            'ai_agent.policy.cannot_pay' => 'لا يمكنك الدفع حالياً لأن الطلب ما زال في مرحلة '
                .($replace['stage'] ?? '')
                .'. الخطوة الحالية هي '
                .($replace['next_step'] ?? '')
                .'.',
            'ai_agent.policy.cannot_book_appointment' => 'لا يمكنك حجز موعد قبل إكمال المتطلبات السابقة. الخطوة الحالية هي '
                .($replace['next_step'] ?? '')
                .'.',
            'ai_agent.policy.cannot_show_fee' => 'لا يمكن عرض رسوم هذا الطلب في مرحلة '
                .($replace['stage'] ?? '')
                .'. الخطوة الحالية هي '
                .($replace['next_step'] ?? '')
                .'.',
            'ai_agent.policy.action_blocked' => 'لا يمكن تنفيذ هذه العملية في مرحلة '
                .($replace['stage'] ?? '')
                .'. الخطوة الحالية هي '
                .($replace['next_step'] ?? '')
                .'.',
            'ai_agent.policy.tests_before_payment' => 'لا يمكنك عرض الاختبارات المتاحة قبل إكمال عملية الدفع. الخطوة الحالية هي '
                .($replace['next_step'] ?? '')
                .'.',
            'ai_agent.policy.tests_while_draft' => 'لا يمكنك عرض الاختبارات المتاحة حالياً لأن الطلب ما زال في مرحلة المسودة. الخطوة الحالية هي '
                .($replace['next_step'] ?? '')
                .'.',
            'ai_agent.policy.tests_blocked' => 'لا يمكنك عرض الاختبارات المتاحة في مرحلة '
                .($replace['stage'] ?? '')
                .'. الخطوة الحالية هي '
                .($replace['next_step'] ?? '')
                .'.',
            'ai_agent.policy.slots_before_payment' => 'لا يمكنك حجز موعد قبل دفع الرسوم. الخطوة الحالية هي '
                .($replace['next_step'] ?? '')
                .'.',
            'ai_agent.policy.slots_while_draft' => 'لا يمكنك عرض مواعيد الاختبارات حالياً لأن الطلب ما زال في مرحلة المسودة. الخطوة الحالية هي '
                .($replace['next_step'] ?? '')
                .'.',
            'ai_agent.policy.slots_blocked' => 'لا يمكنك عرض مواعيد الاختبارات في مرحلة '
                .($replace['stage'] ?? '')
                .'. الخطوة الحالية هي '
                .($replace['next_step'] ?? '')
                .'.',
            'ai_agent.profile.pending_review' => 'ملفك الشخصي قيد المراجعة حالياً. لا يمكنك تنفيذ هذه العملية قبل الموافقة على البيانات.',
            'ai_agent.profile.rejected' => 'تم رفض بيانات ملفك الشخصي. يرجى تعديل البيانات وإعادة إرسالها للمراجعة قبل استخدام الخدمات.',
            'ai_agent.profile.incomplete' => 'يرجى إكمال بيانات الملف الشخصي قبل استخدام هذه الخدمة.',
            default => 'عذراً، تعذر عرض الرسالة حالياً. يرجى المحاولة لاحقاً أو التواصل مع الدعم.',
        };
    }

    /**
     * @param  array<string, mixed>  $replace
     */
    private static function generalFallbackEn(string $suffix, array $replace): string
    {
        return match ($suffix) {
            'ai_agent.existing_active_application' => 'You already have an active '
                .($replace['label'] ?? 'in-progress')
                .' driving license application. You can track your current application instead of creating a new one.',
            'ai_agent.no_active_applications' => 'You have no current applications to view their status.',
            'ai_agent.multiple_active_applications' => 'You have more than one application in progress. Which application do you want to check?'
                .(isset($replace['summary']) ? "\n".$replace['summary'] : ''),
            'ai_agent.status_prompt_confirm' => 'I found an application in progress. Would you like to view its status?',
            'ai_agent.application_status.with_next_step' => 'Application '
                .($replace['number'] ?? '')
                .' status is: '
                .($replace['status'] ?? '')
                .'. '
                .($replace['next_step'] ?? ''),
            'ai_agent.required_documents.no_applications' => 'You have no current application to view required documents. I can help you create a new application if you want.',
            'ai_agent.required_documents.multiple_applications' => 'You have more than one application in progress. Please specify the application number for which you want to see required documents.'
                .(isset($replace['summary']) ? "\n".$replace['summary'] : ''),
            'ai_agent.required_documents.list' => 'The required documents for your application are: '
                .($replace['documents'] ?? '').'.',
            'ai_agent.required_documents.already_uploaded_hint' => 'Some documents are already uploaded, and you can track the status of each document from the application documents page.',
            'ai_agent.required_documents.stage_completed_hint' => 'The documents for this application have been reviewed or the documents stage has been completed. You can track the current step from the application status.',
            'ai_agent.required_documents.unavailable' => 'I could not fetch the required documents for this application at the moment.',
            'ai_agent.appointments.current.single' => 'Yes, an appointment has been booked for '
                .($replace['test'] ?? 'the test')
                .' on '
                .($replace['date'] ?? '')
                .' at '
                .($replace['time'] ?? '')
                .'.',
            'ai_agent.appointments.current.multiple' => 'You have more than one appointment related to this application.',
            'ai_agent.appointments.current.line' => '- '
                .($replace['test'] ?? 'the test')
                .' on '
                .($replace['date'] ?? '')
                .' at '
                .($replace['time'] ?? ''),
            'ai_agent.appointments.current.none' => 'You have no current appointment booked for this application. You can view available slots and book a suitable appointment.',
            'ai_agent.appointments.current.no_application' => 'You have no current application to view its appointment.',
            'ai_agent.appointments.current.choose_application' => 'You have more than one application in progress. Please specify the application number for which you want to view the appointment.'
                .(isset($replace['summary']) && $replace['summary'] !== '' ? "\n".$replace['summary'] : ''),
            'ai_agent.appointments.slots.choose' => 'Here are the available slots. Please choose a suitable appointment.',
            'ai_agent.appointments.slots.choose_for_test' => 'Here are the available slots for '
                .($replace['test'] ?? 'the test')
                .'. Choose a suitable slot from the list.',
            'ai_agent.appointments.slots.loading_for_test' => 'I will show the available slots for '
                .($replace['test'] ?? 'the test')
                .'.',
            'ai_agent.appointments.slots.none' => 'There are no available slots right now. Please try again later.',
            'ai_agent.appointments.slots.none_for_test' => 'There are no available slots right now for '
                .($replace['test'] ?? 'the test')
                .'. Please try again later.',
            'ai_agent.appointments.book.first_available_confirm' => 'I can book the first available slot for '
                .($replace['test'] ?? 'the test')
                .' on '
                .($replace['date'] ?? '')
                .' at '
                .($replace['time'] ?? '')
                .'. Do you confirm the booking?',
            'ai_agent.appointments.slots.stale' => 'The selected slot is no longer available. Please choose another slot.',
            'ai_agent.appointments.slots.invalid_choice' => 'The selected option is not among the offered slots.',
            'ai_agent.appointments.slots.error_application' => 'Could not resolve the application for booking.',
            'ai_agent.appointments.choose.select' => 'Please choose the appointment you want.',
            'ai_agent.appointments.choose.none' => 'You have no booked appointment for this operation right now.',
            'ai_agent.appointments.choose.not_found' => 'The appointment was not found or you do not have access to it.',
            'ai_agent.appointments.choose.invalid' => 'The selected option is not among the offered appointments.',
            'ai_agent.appointments.confirm.prompt' => 'Do you confirm proceeding with the selected appointment?',
            'ai_agent.appointments.cancel.confirm_prompt' => 'Do you confirm cancelling this appointment?',
            'ai_agent.appointments.book.vague' => 'I can show the available slots. Choose a suitable slot then confirm the booking.',
            'ai_agent.appointments.book.success' => 'An appointment has been booked for '
                .($replace['test'] ?? 'the test')
                .' on '
                .($replace['date'] ?? '')
                .' at '
                .($replace['time'] ?? '')
                .'.',
            'ai_agent.appointments.book.success_short' => 'The appointment for '
                .($replace['test'] ?? 'the test')
                .' was booked successfully.',
            'ai_agent.appointments.reschedule.success' => 'The appointment for '
                .($replace['test'] ?? 'the test')
                .' was rescheduled to '
                .($replace['date'] ?? '')
                .' at '
                .($replace['time'] ?? '')
                .'.',
            'ai_agent.appointments.cancel.success' => 'The appointment for '
                .($replace['test'] ?? 'the test')
                .' was cancelled successfully.',
            'ai_agent.appointments.test_fallback' => 'the test',
            'ai_agent.other_license.none_eligible' => 'You have no license eligible for this service right now.',
            'ai_agent.other_license.choose' => 'You have more than one license. Please choose the license for this service.',
            'ai_agent.other_license.invalid_choice' => 'The selected option is not among the offered licenses.',
            'ai_agent.other_license.confirm' => 'I found your '
                .($replace['label'] ?? '')
                .' driving license number '
                .($replace['number'] ?? '')
                .'. Do you want to create a '
                .($replace['service'] ?? 'service')
                .' application for it?',
            'ai_agent.other_license.service.renew_license' => 'renewal',
            'ai_agent.other_license.service.lost_replacement' => 'lost replacement',
            'ai_agent.other_license.service.damaged_replacement' => 'damaged replacement',
            'ai_agent.payment.start.confirm' => 'I can prepare the application fee payment. Do you confirm?',
            'ai_agent.payment.fee.reply' => 'The fee for application '
                .($replace['number'] ?? '')
                .' is '
                .($replace['amount'] ?? '')
                .' '
                .($replace['currency'] ?? '')
                .'. You can proceed to payment when ready.',
            'ai_agent.payment.fee.unavailable' => 'I could not fetch the fee for this application right now.',
            'ai_agent.payment.status.loading' => 'I will show the payment status for this application.',
            'ai_agent.payment.status.paid' => 'The fee for application '
                .($replace['number'] ?? '')
                .' has been paid successfully.',
            'ai_agent.payment.status.pending' => 'Application '
                .($replace['number'] ?? '')
                .' is still awaiting payment.',
            'ai_agent.payment.status.unknown' => 'Here is the current payment status for your application.',
            'ai_agent.licenses.loading' => 'I will show your driving licenses.',
            'ai_agent.fines.loading' => 'I will show your current fines.',
            'ai_agent.fines.pay_unsupported' => 'Paying fines through the assistant is not available yet. You can review fines in the app.',
            'ai_agent.tests.loading' => 'I will show the available tests for your application and the status of each test.',
            'ai_agent.tests.none' => 'There is no test available to book right now. Please follow the current step for your application.',
            'ai_agent.tests.unavailable' => 'I could not fetch the available tests for this application right now.',
            'ai_agent.tests.single_available' => 'The currently available exam is '
                .($replace['name'] ?? '')
                .'.',
            'ai_agent.tests.multiple_available' => 'The currently available tests are: '
                .($replace['names'] ?? '')
                .'.',
            'ai_agent.application_status.simple' => 'Application '
                .($replace['number'] ?? '')
                .' status is: '
                .($replace['status'] ?? '')
                .'.',
            'ai_agent.test_results.empty' => 'There are no test results recorded for this application right now.',
            'ai_agent.test_results.header' => 'Here are your recorded test results:',
            'ai_agent.test_results.test_fallback' => 'the test',
            'ai_agent.test_results.attempt' => ' (attempt '.($replace['attempt'] ?? '').')',
            'ai_agent.test_results.on_date' => ' on '.($replace['date'] ?? ''),
            'ai_agent.test_results.line' => '- '
                .($replace['test'] ?? '')
                .': '
                .($replace['result'] ?? '')
                .($replace['attempt_part'] ?? '')
                .($replace['date_part'] ?? '')
                .'.',
            'ai_agent.test_results.result.passed' => 'Passed',
            'ai_agent.test_results.result.failed' => 'Failed',
            'ai_agent.test_results.result.no_show' => 'No show',
            'ai_agent.document_flow.selection_required' => 'Please choose upload via the assistant or manual upload.',
            'ai_agent.document_flow.no_eligible_application' => 'You currently have no application that allows document upload.',
            'ai_agent.document_flow.multiple_applications' => 'You have more than one application that needs documents. Please choose the required application.',
            'ai_agent.document_flow.application_option' => ($replace['service'] ?? 'Application')
                .' — '
                .($replace['license'] ?? '')
                .' license — #'
                .($replace['id'] ?? ''),
            'ai_agent.document_flow.service_fallback' => 'Application',
            'ai_agent.document_flow.unknown_interaction' => 'Unknown interaction action.',
            'ai_agent.document_flow.file_required' => 'Please attach the required document file.',
            'ai_agent.document_flow.multiple_files_with_label' => 'More than one file was attached. You chose to upload «'
                .($replace['label'] ?? 'the document')
                .'», so please attach only one file for this document.',
            'ai_agent.document_flow.multiple_files_reply' => 'More than one file was attached, while document «'
                .($replace['label'] ?? 'the document')
                .'» was selected. Please attach only one file for the selected document, then try again.',
            'ai_agent.document_flow.multiple_files_simple' => 'More than one file was attached. Please attach only one file.',
            'ai_agent.document_flow.upload_token_app_mismatch' => 'Mismatch between the upload token and the submitted application ID.',
            'ai_agent.document_flow.upload_token_doc_mismatch' => 'Mismatch between the upload token and the submitted document ID.',
            'ai_agent.document_flow.upload_failed' => 'Could not upload the document.',
            'ai_agent.document_flow.upload_state_conflict' => 'Could not finish document upload processing due to a state conflict.',
            'ai_agent.document_flow.document_fallback' => 'the document',
            'ai_agent.document_flow.uploaded_remaining' => 'Document «'
                .($replace['label'] ?? 'the document')
                .'» was uploaded successfully. Remaining documents: '
                .($replace['remaining'] ?? '')
                .'. Please choose the next document.',
            'ai_agent.document_flow.uploaded_complete' => 'Document «'
                .($replace['label'] ?? 'the document')
                .'» was uploaded successfully. All required documents are complete.',
            'ai_agent.document_flow.already_submitted' => 'Documents were already submitted for review.',
            'ai_agent.document_flow.consent_confirmation_message' => 'Prior explicit consent within the assistant document upload flow.',
            'ai_agent.document_flow.submitted_for_review' => 'All required documents were uploaded and sent to the document review section successfully. Your application status is now «Documents under review». Please wait for the review result; you will be notified when the status is updated.',
            'ai_agent.document_flow.submission_failed_after_upload' => 'All documents were uploaded, but they could not be submitted for review because the application status changed. Please refresh the application status and try again.',
            'ai_agent.document_flow.upload_offer' => 'The required documents to complete your application are: '
                .($replace['documents'] ?? '')
                .".\n\n"
                .'Would you like to upload these documents through the assistant? When all documents are uploaded, I will send them directly to the document review section.',
            'ai_agent.document_flow.button_agent_upload' => 'Yes, upload and submit via assistant',
            'ai_agent.document_flow.button_manual_upload' => 'No, I will upload manually',
            'ai_agent.document_flow.cannot_confirm_agent_upload' => 'Assistant document upload cannot be confirmed at this stage.',
            'ai_agent.document_flow.no_documents_to_upload' => 'There are no documents that need uploading for this application right now.',
            'ai_agent.document_flow.choose_document' => 'Please choose the document you want to upload now.',
            'ai_agent.document_flow.cannot_choose_manual' => 'Manual upload cannot be chosen at this stage.',
            'ai_agent.document_flow.manual_guidance' => 'Okay. You can upload documents manually from the application documents page. Go to your applications list, choose the application, open the documents section, and upload the required files.',
            'ai_agent.document_flow.button_go_to_documents' => 'Go to documents page',
            'ai_agent.document_flow.application_selection_unexpected' => 'Application selection is unexpected at this stage.',
            'ai_agent.document_flow.application_not_eligible' => 'The selected application no longer allows document upload.',
            'ai_agent.document_flow.document_selection_unexpected' => 'Document selection is unexpected at this stage.',
            'ai_agent.document_flow.selection_token_app_mismatch' => 'The document selection token does not match the application bound to this session.',
            'ai_agent.document_flow.selection_token_invalid' => 'The document selection token is invalid.',
            'ai_agent.document_flow.required_document_missing' => 'The required document was not found.',
            'ai_agent.document_flow.document_not_required' => 'This document is not required for this application.',
            'ai_agent.document_flow.cannot_replace_approved' => 'An approved document cannot be replaced.',
            'ai_agent.document_flow.awaiting_file' => 'Please upload the file for document «'
                .($replace['label'] ?? 'the document')
                .'» now. Make sure to attach only one file for this specific document, and do not attach a different document instead.',
            'ai_agent.document_flow.cancelled' => 'The assistant document upload flow was cancelled. You can request the required documents at any time.',
            'ai_agent.document_flow.choose_application_first' => 'Please choose the application first.',
            'ai_agent.document_flow.session_application_not_eligible' => 'The application bound to this session no longer allows document upload.',
            'ai_agent.document_flow.reupload_hint' => 'Please re-upload the document',
            'ai_agent.document_flow.names_unspecified' => 'unspecified',
            'ai_agent.document_flow.session_closed' => 'The AI assistant session is closed.',
            'ai_agent.document_upload.complete_can_submit' => 'The document was uploaded. All required documents are complete. You can now submit them for review by saying "submit documents for review".',
            'ai_agent.document_upload.missing_remaining' => 'The document was uploaded. Documents still missing before review submission: '
                .($replace['names'] ?? '')
                .'.',
            'ai_agent.document_upload.rejected_remaining' => 'The document was uploaded. Rejected documents still need re-upload before review submission: '
                .($replace['names'] ?? '')
                .'. Please re-upload them.',
            'ai_agent.document_upload.success' => 'The document was uploaded successfully.',
            'ai_agent.document_status.pending_review' => 'Pending review',
            'ai_agent.document_status.approved' => 'Approved',
            'ai_agent.document_status.rejected' => 'Rejected',
            'ai_agent.pending.expired' => 'The application selection process has expired. Please request the service again.',
            'ai_agent.pending.not_found' => 'There is no pending application selection process.',
            'ai_agent.pending.state_invalid' => 'The application selection process state is invalid for this action.',
            'ai_agent.pending.retry_required' => 'Could not continue the process temporarily. Please select the application again.',
            'ai_agent.pending.appointment_choice_missing' => 'There is no pending appointment selection process.',
            'ai_agent.pending.license_choice_missing' => 'There is no pending license selection process.',
            'ai_agent.selection.ambiguous' => 'I found more than one matching application. Please choose the intended application carefully.',
            'ai_agent.selection.unresolved' => 'I could not determine the intended application. Please choose one of the listed applications.',
            'ai_agent.selection.path_unavailable' => 'Application selection is not available through this path right now.',
            'ai_agent.selection.no_longer_available' => 'The listed applications are no longer available. Please request the service again.',
            'ai_agent.selection.prompt' => 'Please choose one of the listed applications.',
            'ai_agent.selection.invalid' => 'The selected application is not among the offered options.',
            'ai_agent.selection.no_longer_eligible' => 'The selected application is no longer eligible for this operation.',
            'ai_agent.selection.multiple_prompt' => 'You have more than one application in progress. Please choose the required application.',
            'ai_agent.selection.confirm_continue' => 'The application has been selected. Do you confirm proceeding?',
            'ai_agent.selection.cancelled' => 'The application selection process has been cancelled. You can request any other service.',
            'ai_agent.selection.subtitle' => ($replace['license'] ?? '')
                .' license — '
                .($replace['status'] ?? ''),
            'ai_agent.selection.application_summary_line' => '- '
                .($replace['number'] ?? '')
                .' — '
                .($replace['license'] ?? '')
                .' driving license — '
                .($replace['status'] ?? ''),
            'ai_agent.selection.appointment_token_invalid' => 'The appointment selection token is invalid.',
            'ai_agent.selection.license_token_invalid' => 'The license selection token is invalid.',
            'ai_agent.application.not_owned' => 'The application was not found or you do not have access to it.',
            'ai_agent.action.arguments_incomplete' => 'Cannot continue before the required data is complete.',
            'ai_agent.existing_active_application_for_license' => 'You already have an active application for this license and service. You can continue the current application instead of creating a new one.',
            'ai_agent.policy.tests_not_required' => 'This service does not require booking tests. The current step is to complete documents and payment until the license is issued.',
            'ai_agent.policy.cannot_pay' => 'You cannot pay right now because the application is still in the '
                .($replace['stage'] ?? '')
                .' stage. The current step is '
                .($replace['next_step'] ?? '')
                .'.',
            'ai_agent.policy.cannot_book_appointment' => 'You cannot book an appointment before completing the previous requirements. The current step is '
                .($replace['next_step'] ?? '')
                .'.',
            'ai_agent.policy.cannot_show_fee' => 'The fee for this application cannot be shown in the '
                .($replace['stage'] ?? '')
                .' stage. The current step is '
                .($replace['next_step'] ?? '')
                .'.',
            'ai_agent.policy.action_blocked' => 'This operation cannot be performed in the '
                .($replace['stage'] ?? '')
                .' stage. The current step is '
                .($replace['next_step'] ?? '')
                .'.',
            'ai_agent.policy.tests_before_payment' => 'You cannot view available tests before completing payment. The current step is '
                .($replace['next_step'] ?? '')
                .'.',
            'ai_agent.policy.tests_while_draft' => 'You cannot view available tests right now because the application is still in draft. The current step is '
                .($replace['next_step'] ?? '')
                .'.',
            'ai_agent.policy.tests_blocked' => 'You cannot view available tests in the '
                .($replace['stage'] ?? '')
                .' stage. The current step is '
                .($replace['next_step'] ?? '')
                .'.',
            'ai_agent.policy.slots_before_payment' => 'You cannot book an appointment before paying the fees. The current step is '
                .($replace['next_step'] ?? '')
                .'.',
            'ai_agent.policy.slots_while_draft' => 'You cannot view test appointment slots right now because the application is still in draft. The current step is '
                .($replace['next_step'] ?? '')
                .'.',
            'ai_agent.policy.slots_blocked' => 'You cannot view test appointment slots in the '
                .($replace['stage'] ?? '')
                .' stage. The current step is '
                .($replace['next_step'] ?? '')
                .'.',
            'ai_agent.profile.pending_review' => 'Your profile is currently under review. You cannot perform this operation before the data is approved.',
            'ai_agent.profile.rejected' => 'Your profile data was rejected. Please edit the data and resubmit it for review before using services.',
            'ai_agent.profile.incomplete' => 'Please complete your profile data before using this service.',
            default => 'Sorry, unable to display the message at the moment. Please try again later or contact support.',
        };
    }

    /**
     * @param  array<string, mixed>  $replace
     */
    private static function applicationNextStepFallback(string $status, array $replace = [], ?string $locale = null): string
    {
        $locale = $locale ?? self::getLocale();
        
        return $locale === 'en'
            ? self::applicationNextStepFallbackEn($status, $replace)
            : self::applicationNextStepFallbackAr($status, $replace);
    }

    /**
     * @param  array<string, mixed>  $replace
     */
    private static function applicationNextStepFallbackAr(string $status, array $replace = []): string
    {
        $message = match ($status) {
            'no_applications' => 'لا يوجد لديك طلبات حالية لتحديد الخطوة التالية. يمكنني مساعدتك في إنشاء طلب رخصة جديد إذا أردت.',
            'multiple_applications' => 'لديك أكثر من طلب قيد المتابعة. من فضلك حدد رقم الطلب الذي تريد معرفة خطوته التالية.'
                .(isset($replace['summary']) ? "\n".$replace['summary'] : ''),
            'draft' => 'طلبك حالياً في مرحلة المسودة. الخطوة التالية هي رفع الوثائق المطلوبة ثم إرسالها للمراجعة.',
            'documents_rejected' => 'تم رفض بعض الوثائق في طلبك. الخطوة التالية هي مراجعة سبب الرفض وإعادة رفع الوثائق المطلوبة بشكل صحيح.',
            'documents_under_review' => 'وثائق طلبك قيد المراجعة حالياً من قبل الموظف المختص. لا تحتاج لاتخاذ إجراء حالياً، وسيتم إعلامك عند انتهاء المراجعة.',
            'payment_pending' => 'تمت الموافقة على وثائقك. الخطوة التالية هي دفع الرسوم المطلوبة لإكمال الطلب.',
            'payment_completed' => 'تم الدفع بنجاح. الخطوة التالية هي حجز موعد الاختبار الأول المتاح.',
            'appointment_pending' => 'طلبك جاهز لحجز موعد الاختبار. الخطوة التالية هي اختيار موعد مناسب للاختبار المتاح حالياً.',
            'in_testing' => 'طلبك حالياً في مرحلة الاختبارات. الخطوة التالية هي متابعة الاختبار الحالي أو انتظار تسجيل النتيجة من الموظف المختص.',
            'waiting_retest' => 'طلبك بانتظار إعادة الاختبار. الخطوة التالية هي حجز موعد جديد لنفس الاختبار الذي لم يتم اجتيازه.',
            'approved' => 'تم اجتياز جميع المتطلبات وأصبح طلبك مؤهلاً لإصدار الرخصة. الخطوة التالية هي انتظار إصدار الرخصة من الموظف المختص.',
            'administrative_review' => 'طلبك قيد المراجعة الإدارية حالياً. لا تحتاج لاتخاذ إجراء حالياً حتى يتم الانتهاء من المراجعة.',
            'license_issued' => 'تم إصدار الرخصة الخاصة بطلبك بنجاح. يمكنك الآن عرض تفاصيل الرخصة من قسم الرخص.',
            'rejected' => 'تم رفض الطلب. يمكنك مراجعة سبب الرفض من تفاصيل الطلب.',
            'cancelled' => 'تم إلغاء هذا الطلب. يمكنك إنشاء طلب جديد إذا كنت ترغب بمتابعة الخدمة من البداية.',
            'unknown' => 'لم أتمكن من تحديد الخطوة التالية لهذا الطلب. يمكنك فتح تفاصيل الطلب أو التواصل مع الدعم.',
            default => 'لم أتمكن من تحديد الخطوة التالية لهذا الطلب. يمكنك فتح تفاصيل الطلب أو التواصل مع الدعم.',
        };

        return $message;
    }

    /**
     * @param  array<string, mixed>  $replace
     */
    private static function applicationNextStepFallbackEn(string $status, array $replace = []): string
    {
        $message = match ($status) {
            'no_applications' => 'You have no current applications to determine the next step. I can help you create a new driving license application if you want.',
            'multiple_applications' => 'You have more than one application in progress. Please specify the application number for which you want to know the next step.'
                .(isset($replace['summary']) ? "\n".$replace['summary'] : ''),
            'draft' => 'Your application is currently in draft status. The next step is to upload the required documents and submit them for review.',
            'documents_rejected' => 'Some documents in your application have been rejected. The next step is to review the reason for rejection and re-upload the required documents correctly.',
            'documents_under_review' => 'Your application documents are currently under review by the relevant employee. You do not need to take any action now, and you will be notified when the review is complete.',
            'payment_pending' => 'Your documents have been approved. The next step is to pay the required fees to complete the application.',
            'payment_completed' => 'Payment completed successfully. The next step is to book an appointment for the first available test.',
            'appointment_pending' => 'Your application is ready to book a test appointment. The next step is to choose a suitable appointment for the currently available test.',
            'in_testing' => 'Your application is currently in the testing phase. The next step is to track the current test or wait for the result to be recorded by the relevant employee.',
            'waiting_retest' => 'Your application is waiting for a retest. The next step is to book a new appointment for the same test that was not passed.',
            'approved' => 'All requirements have been met and your application is now eligible for license issuance. The next step is to wait for the license to be issued by the relevant employee.',
            'administrative_review' => 'Your application is currently under administrative review. You do not need to take any action now until the review is complete.',
            'license_issued' => 'The license for your application has been issued successfully. You can now view the license details from the licenses section.',
            'rejected' => 'The application has been rejected. You can review the reason for rejection from the application details.',
            'cancelled' => 'This application has been cancelled. You can create a new application if you want to continue the service from the beginning.',
            'unknown' => 'I could not determine the next step for this application. You can open the application details or contact support.',
            default => 'I could not determine the next step for this application. You can open the application details or contact support.',
        };

        return $message;
    }
}
