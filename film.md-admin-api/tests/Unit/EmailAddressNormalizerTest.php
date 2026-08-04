<?php

namespace Tests\Unit;

use App\Services\EmailAddressNormalizer;
use PHPUnit\Framework\TestCase;

class EmailAddressNormalizerTest extends TestCase
{
    public function test_it_corrects_common_popular_provider_typos(): void
    {
        $normalizer = new EmailAddressNormalizer;

        $examples = [
            'user@gmal.com' => 'user@gmail.com',
            'user@gmial.com' => 'user@gmail.com',
            'user@gmail.con' => 'user@gmail.com',
            'user@gmal.con' => 'user@gmail.com',
            'user@yaho.com' => 'user@yahoo.com',
            'user@outlok.com' => 'user@outlook.com',
            'user@hotmial.com' => 'user@hotmail.com',
            'user@iclud.com' => 'user@icloud.com',
            'user@mail.ry' => 'user@mail.ru',
            'user@yandx.ry' => 'user@yandex.ru',
            'user@protonmail.con' => 'user@protonmail.com',
        ];

        foreach ($examples as $input => $expected) {
            $this->assertSame($expected, $normalizer->normalize($input));
        }
    }

    public function test_it_does_not_guess_unknown_or_company_domains(): void
    {
        $normalizer = new EmailAddressNormalizer;

        $this->assertSame('user@company.con', $normalizer->normalize('user@company.con'));
        $this->assertSame('user@email.com', $normalizer->normalize('user@email.com'));
        $this->assertSame('user@custom-domain.md', $normalizer->normalize('user@custom-domain.md'));
    }
}
