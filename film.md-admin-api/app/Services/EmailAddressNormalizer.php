<?php

declare(strict_types=1);

namespace App\Services;

final class EmailAddressNormalizer
{
    public const POPULAR_DOMAINS = [
        'gmail.com',
        'googlemail.com',
        'yahoo.com',
        'outlook.com',
        'hotmail.com',
        'live.com',
        'icloud.com',
        'mail.ru',
        'inbox.ru',
        'list.ru',
        'bk.ru',
        'yandex.ru',
        'rambler.ru',
        'proton.me',
        'protonmail.com',
    ];

    private const DOMAIN_CORRECTIONS = [
        'gmal.com' => 'gmail.com',
        'gmial.com' => 'gmail.com',
        'gmai.com' => 'gmail.com',
        'gamil.com' => 'gmail.com',
        'gmaill.com' => 'gmail.com',
        'gmail.co' => 'gmail.com',
        'gmail.con' => 'gmail.com',
        'gmail.cm' => 'gmail.com',
        'gmail.om' => 'gmail.com',
        'gmail.comm' => 'gmail.com',
        'gmail.comn' => 'gmail.com',
        'gmail.cim' => 'gmail.com',
        'gmail.cpm' => 'gmail.com',
        'gmail' => 'gmail.com',
        'gmal' => 'gmail.com',
        'googlemai.com' => 'googlemail.com',
        'googlemail.co' => 'googlemail.com',
        'googlemail.con' => 'googlemail.com',
        'yaho.com' => 'yahoo.com',
        'yhoo.com' => 'yahoo.com',
        'yahooo.com' => 'yahoo.com',
        'yahho.com' => 'yahoo.com',
        'yaoo.com' => 'yahoo.com',
        'yahoo.co' => 'yahoo.com',
        'yahoo.con' => 'yahoo.com',
        'yahoo.cm' => 'yahoo.com',
        'outlok.com' => 'outlook.com',
        'outllook.com' => 'outlook.com',
        'outloo.com' => 'outlook.com',
        'outlook.co' => 'outlook.com',
        'outlook.con' => 'outlook.com',
        'outlook.cm' => 'outlook.com',
        'hotmal.com' => 'hotmail.com',
        'hotmial.com' => 'hotmail.com',
        'hotmai.com' => 'hotmail.com',
        'hotmaill.com' => 'hotmail.com',
        'hotmail.co' => 'hotmail.com',
        'hotmail.con' => 'hotmail.com',
        'hotmail.cm' => 'hotmail.com',
        'live.co' => 'live.com',
        'live.con' => 'live.com',
        'iclud.com' => 'icloud.com',
        'iclou.com' => 'icloud.com',
        'icloud.co' => 'icloud.com',
        'icloud.con' => 'icloud.com',
        'icloud.cm' => 'icloud.com',
        'mai.ru' => 'mail.ru',
        'mal.ru' => 'mail.ru',
        'mail.ry' => 'mail.ru',
        'mailru' => 'mail.ru',
        'inbox.ry' => 'inbox.ru',
        'inboxru' => 'inbox.ru',
        'list.ry' => 'list.ru',
        'listru' => 'list.ru',
        'bk.ry' => 'bk.ru',
        'bkru' => 'bk.ru',
        'yandx.ru' => 'yandex.ru',
        'yanex.ru' => 'yandex.ru',
        'yandex.ry' => 'yandex.ru',
        'yandexru' => 'yandex.ru',
        'ramber.ru' => 'rambler.ru',
        'rambler.ry' => 'rambler.ru',
        'ramblerru' => 'rambler.ru',
        'protom.me' => 'proton.me',
        'proton.ne' => 'proton.me',
        'protonmal.com' => 'protonmail.com',
        'protonmai.com' => 'protonmail.com',
        'protonmail.co' => 'protonmail.com',
        'protonmail.con' => 'protonmail.com',
    ];

    private const PROVIDER_CORRECTIONS = [
        'gmal' => 'gmail',
        'gmial' => 'gmail',
        'gmai' => 'gmail',
        'gamil' => 'gmail',
        'gmaill' => 'gmail',
        'googlemai' => 'googlemail',
        'yaho' => 'yahoo',
        'yhoo' => 'yahoo',
        'yahooo' => 'yahoo',
        'yahho' => 'yahoo',
        'yaoo' => 'yahoo',
        'outlok' => 'outlook',
        'outllook' => 'outlook',
        'outloo' => 'outlook',
        'hotmal' => 'hotmail',
        'hotmial' => 'hotmail',
        'hotmai' => 'hotmail',
        'hotmaill' => 'hotmail',
        'iclud' => 'icloud',
        'iclou' => 'icloud',
        'mai' => 'mail',
        'mal' => 'mail',
        'yandx' => 'yandex',
        'yanex' => 'yandex',
        'ramber' => 'rambler',
        'protom' => 'proton',
        'protonmal' => 'protonmail',
        'protonmai' => 'protonmail',
    ];

    private const TLD_CORRECTIONS = [
        'con' => 'com',
        'co' => 'com',
        'cm' => 'com',
        'om' => 'com',
        'comm' => 'com',
        'comn' => 'com',
        'cim' => 'com',
        'cpm' => 'com',
        'ry' => 'ru',
    ];

    public function normalize(string $email): string
    {
        $email = trim($email);
        $atPosition = strrpos($email, '@');

        if ($atPosition === false || $atPosition === 0 || $atPosition === strlen($email) - 1) {
            return strtolower($email);
        }

        $localPart = substr($email, 0, $atPosition);
        $domain = strtolower(substr($email, $atPosition + 1));
        $domain = $this->matchPopularDomain($domain);

        return strtolower($localPart).'@'.$domain;
    }

    private function matchPopularDomain(string $domain): string
    {
        if (isset(self::DOMAIN_CORRECTIONS[$domain])) {
            return self::DOMAIN_CORRECTIONS[$domain];
        }

        $parts = explode('.', $domain);
        if (count($parts) !== 2) {
            return $domain;
        }

        $provider = self::PROVIDER_CORRECTIONS[$parts[0]] ?? $parts[0];
        $tld = self::TLD_CORRECTIONS[$parts[1]] ?? $parts[1];
        $candidate = $provider.'.'.$tld;

        return in_array($candidate, self::POPULAR_DOMAINS, true) ? $candidate : $domain;
    }
}
