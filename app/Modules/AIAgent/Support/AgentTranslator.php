<?php

namespace App\Modules\AIAgent\Support;

use Illuminate\Support\Facades\Lang;

class AgentTranslator
{
    private const AGENT_LOCALE = 'ar';

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

        if (Lang::has($fullKey, self::AGENT_LOCALE)) {
            $translated = Lang::get($fullKey, $replace, self::AGENT_LOCALE);

            if (is_string($translated) && $translated !== $fullKey) {
                return $translated;
            }
        }

        return self::fallbackForKey($fullKey, $replace);
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

        if (str_starts_with($suffix, 'ai_agent.application_next_step.')) {
            $status = substr($suffix, strlen('ai_agent.application_next_step.'));

            return self::applicationNextStepFallback($status, $replace);
        }

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
            default => 'عذراً، تعذر عرض الرسالة حالياً. يرجى المحاولة لاحقاً أو التواصل مع الدعم.',
        };
    }

    /**
     * @param  array<string, mixed>  $replace
     */
    private static function applicationNextStepFallback(string $status, array $replace = []): string
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
}
