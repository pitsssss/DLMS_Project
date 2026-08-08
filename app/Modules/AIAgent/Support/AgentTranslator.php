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

    public static function message(string $key, array $replace = []): string
    {
        $fullKey = str_starts_with($key, 'messages.') ? $key : 'messages.'.$key;
        $locale = self::getLocale();

        if (Lang::has($fullKey, $locale)) {
            $translated = Lang::get($fullKey, $replace, $locale);

            if (is_string($translated) && $translated !== $fullKey) {
                return $translated;
            }
        }

        return self::fallbackForKey($fullKey, $replace);
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
    private static function fallbackForKey(string $fullKey, array $replace): string
    {
        $suffix = str_replace('messages.', '', $fullKey);
        $locale = self::getLocale();

        if (str_starts_with($suffix, 'ai_agent.application_next_step.')) {
            $status = substr($suffix, strlen('ai_agent.application_next_step.'));

            return self::applicationNextStepFallback($status, $replace);
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
            'ai_agent.appointments.current.none' => 'لا يوجد لديك موعد محجوز حالياً لهذا الطلب. يمكنك عرض المواعيد المتاحة وحجز موعد مناسب.',
            'ai_agent.appointments.current.no_application' => 'لا يوجد لديك طلب حالي لعرض موعده.',
            'ai_agent.appointments.current.choose_application' => 'لديك أكثر من طلب قيد المتابعة. من فضلك حدد رقم الطلب الذي تريد عرض موعده.'
                .(isset($replace['summary']) && $replace['summary'] !== '' ? "\n".$replace['summary'] : ''),
            'ai_agent.appointments.slots.choose' => 'هذه المواعيد المتاحة. يرجى اختيار الموعد المناسب.',
            'ai_agent.appointments.slots.none' => 'لا توجد مواعيد متاحة حالياً. يرجى المحاولة لاحقاً.',
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
            'ai_agent.appointments.current.none' => 'You have no current appointment booked for this application. You can view available slots and book a suitable appointment.',
            'ai_agent.appointments.current.no_application' => 'You have no current application to view its appointment.',
            'ai_agent.appointments.current.choose_application' => 'You have more than one application in progress. Please specify the application number for which you want to view the appointment.'
                .(isset($replace['summary']) && $replace['summary'] !== '' ? "\n".$replace['summary'] : ''),
            'ai_agent.appointments.slots.choose' => 'Here are the available slots. Please choose a suitable appointment.',
            'ai_agent.appointments.slots.none' => 'There are no available slots right now. Please try again later.',
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
            default => 'Sorry, unable to display the message at the moment. Please try again later or contact support.',
        };
    }

    /**
     * @param  array<string, mixed>  $replace
     */
    private static function applicationNextStepFallback(string $status, array $replace = []): string
    {
        $locale = self::getLocale();
        
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
