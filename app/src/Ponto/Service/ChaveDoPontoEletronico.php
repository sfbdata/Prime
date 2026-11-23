<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Repository\UserTenantRepository;

/**
 * A chave "Registra ponto eletrônico" na batida (spec seguranca-b-jornada, regra 2): desligada no escritório, a pessoa
 * não bate ponto ali, mesmo com a permissão do módulo. Sem vínculo (o Master que entra sem vínculo) não há chave a ler,
 * e a batida segue como antes.
 */
final class ChaveDoPontoEletronico
{
    public const MENSAGEM_DESLIGADA = 'O registro de ponto eletrônico está desligado para você neste escritório. Fale com o administrador.';

    /** @var array<string, string> texto da constante => chave no catálogo `ponto_servidor` (idioma-ponto-php B2) */
    public const CHAVE_DO_TEXTO = [
        self::MENSAGEM_DESLIGADA => 'ponto_servidor.batida.chave.desligada',
    ];

    public function __construct(
        private readonly UserTenantRepository $vinculos,
    ) {
    }

    /** A chave no catálogo de uma das constantes de texto; qualquer outro texto é erro de programação. */
    public static function chaveDe(string $texto): string
    {
        return self::CHAVE_DO_TEXTO[$texto] ?? throw new \LogicException(sprintf('Texto da chave do ponto sem chave no catálogo: "%s".', $texto));
    }

    /**
     * O texto de uma das constantes, com a chave para o controller traduzir.
     *
     * @param array<string, string> $parametros
     */
    public static function texto(string $texto, array $parametros = []): TextoDoPonto
    {
        return TextoDoPonto::de(self::chaveDe($texto), $texto, $parametros);
    }

    public function estaDesligada(User $user, Tenant $tenant): bool
    {
        return $this->vinculos->findAtivoPorUserETenant($user, $tenant)?->isRegistraPonto() === false;
    }
}
