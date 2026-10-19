<?php

declare(strict_types=1);

namespace App\Contribua\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Repository\UserRepository;
use App\Repository\UserTenantRepository;
use App\Service\NotificacaoService;
use Psr\Log\LoggerInterface;

/**
 * Avisa o(s) Master(s) de algo do Contribua (sugestão nova, apoio informado, motivo, pedido de espaço)
 * pelo sino e pela Central, como o `avisaMaster` do desenho 08.
 *
 * A notificação é de um escritório (o sino isola por escritório). Vai no escritório de origem quando o
 * Master tem vínculo ativo com ele; senão no primeiro escritório do Master. Master sem escritório
 * nenhum não tem sino para receber, e fica sem o aviso.
 */
final class AvisoAoMaster
{
    /** Tipos das notificações do Contribua: na Central, o tipo "Contribua" do desenho (bi-heart, vermelho). */
    public const TIPO_SUGESTAO = 'contribua.sugestao';
    public const TIPO_APOIO = 'contribua.apoio';
    public const TIPO_MOTIVO = 'contribua.motivo';
    public const TIPO_PEDIDO_DE_ESPACO = 'contribua.pedido_espaco';
    /** C4: o banco (Cora) confirmou um apoio por PIX. */
    public const TIPO_APOIO_CONFIRMADO = 'contribua.apoio_confirmado';

    /** O título da notificação é `varchar(255)`: nome, escritório e texto longos não podem estourar. */
    private const TAMANHO_MAXIMO = 255;

    public function __construct(
        private readonly UserRepository $usuarios,
        private readonly UserTenantRepository $vinculos,
        private readonly NotificacaoService $notificacoes,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Nunca lança: o aviso é consequência do que a pessoa fez (a sugestão já está gravada). Uma falha
     * aqui vira log, não erro na tela, e não leva a pessoa a reenviar e duplicar.
     */
    public function avisar(Tenant $origem, string $tipo, string $mensagem, string $url): void
    {
        $mensagem = self::caber($mensagem);
        try {
            foreach ($this->usuarios->findComPapelGlobal('ROLE_SUPER_ADMIN') as $master) {
                $escritorio = $this->escritorioDoAviso($master, $origem);
                if ($escritorio !== null) {
                    $this->notificacoes->criarNotificacao($master, $escritorio, $tipo, $mensagem, $url);
                }
            }
        } catch (\Throwable $falha) {
            $this->logger->error('Contribua: aviso ao Master não foi criado', ['tipo' => $tipo, 'erro' => $falha->getMessage()]);
        }
    }

    public static function caber(string $mensagem): string
    {
        return mb_strlen($mensagem) <= self::TAMANHO_MAXIMO ? $mensagem : rtrim(mb_substr($mensagem, 0, self::TAMANHO_MAXIMO - 3)) . '...';
    }

    private function escritorioDoAviso(User $master, Tenant $origem): ?Tenant
    {
        $escritorios = array_map(static fn ($vinculo): Tenant => $vinculo->getTenant(), $this->vinculos->findActiveByUser($master));
        foreach ($escritorios as $escritorio) {
            if ($escritorio->getId() === $origem->getId()) {
                return $escritorio;
            }
        }

        return $escritorios[0] ?? null;
    }
}
