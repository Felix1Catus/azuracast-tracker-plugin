<?php

declare(strict_types=1);

namespace Plugin\BranchTracker\Service;

use Plugin\BranchTracker\Entity\Branch;

/**
 * A one-time "what's new" notice for the branch player, shown once per branch (not per device): the player
 * gets it with the login or heartbeat response until the branch dismisses it (Branch::$notice_seen).
 * To announce new changes, change ID and the text; every branch then sees the new notice once.
 * Written in Egyptian Arabic for the staff in the branches.
 */
final class BranchNotice
{
    public const string ID = '2026-10-changes';

    /** @return array{id: string, title: string, intro: string, items: array<int, array{title: string, text: string}>, button: string}|null */
    public static function forBranch(Branch $branch): ?array
    {
        if (self::ID === $branch->notice_seen) {
            return null;
        }

        return [
            'id' => self::ID,
            'title' => 'التغييرات الجديدة',
            'intro' => 'أهلاً بيكم، فيه شوية تحديثات جديدة على راديو الفروع:',
            'items' => [
                [
                    'title' => 'وقت الأذان',
                    'text' => 'مع كل أذان الراديو هيسكت لوحده ٣ دقايق، وبعدها المزيكا هترجع تشتغل لوحدها. السكوت ده مقصود ومش عطل، '
                        . 'فمتقفلوش الراديو ومتعملوش ريفريش للصفحة.',
                ],
                [
                    'title' => 'المزيكا',
                    'text' => 'غيّرنا مصدر المزيكا لجودة أحسن، وهتسمعوا أغاني جديدة بتتضاف باستمرار.',
                ],
                [
                    'title' => 'متابعة التشغيل',
                    'text' => 'بقى عندنا متابعة لحالة الراديو في كل فرع: شغال ولا واقف، وعلى أنهي مستوى صوت. '
                        . 'ياريت الراديو يفضل شغال على صوت مناسب طول مواعيد الشغل.',
                ],
            ],
            'button' => 'تمام، فهمت',
        ];
    }
}
