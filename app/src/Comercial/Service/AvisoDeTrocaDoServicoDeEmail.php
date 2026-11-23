<?php

declare(strict_types=1);

namespace App\Comercial\Service;

use App\Comercial\Envio\CarteiroInterface;
use App\Comercial\Envio\CotaDeEnvio;
use App\Comercial\Envio\MensagemDeEmail;
use App\Comercial\Envio\TipoDeEnvio;
use App\Comercial\Repository\EnvioDeEmailRepository;
use App\Entity\Auth\User;
use App\Repository\UserRepository;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * O e-mail ao Master quando o sistema passa de {@see CotaDeEnvio::TROCAR_DE_SERVICO_ACIMA_DE} e-mails em 24 h (pedido do
 * Farlei, 23/11/2026): hora de contratar outro serviço de e-mail. Roda na varredura de 15 min do Comercial; o aviso da
 * moldura mostra o mesmo estado na hora.
 *
 * No máximo um aviso a cada 24 h corridas: a guarda é o próprio registro em `comercial_envio` com a {@see self::ORIGEM}
 * (sem tabela nova), gravado numa transação só para todos os Masters. A varredura é reivindicada no banco, então duas voltas não avisam juntas. Vai a cada Master ativo
 * com e-mail, isento do teto diário (a cota pode estar cheia justamente nessa hora). Só avisa: nada é segurado.
 */
final class AvisoDeTrocaDoServicoDeEmail
{
    public const ORIGEM = 'aviso_troca_servico';

    public function __construct(
        private readonly EnvioDeEmailRepository $envios,
        private readonly UserRepository $usuarios,
        private readonly CarteiroInterface $carteiro,
        private readonly TranslatorInterface $tradutor,
    ) {
    }

    /** @return int quantos avisos entraram na fila (0 = abaixo do limite, já avisado nas últimas 24 h ou sem Master) */
    public function avisarSePreciso(\DateTimeImmutable $agora): int
    {
        $desde = $agora->modify(CotaDeEnvio::JANELA);
        $enviados = $this->envios->contarEnviadosDesde($desde);
        if ($enviados <= CotaDeEnvio::TROCAR_DE_SERVICO_ACIMA_DE || $this->envios->contarDaOrigemDesde(self::ORIGEM, $desde) > 0) {
            return 0;
        }

        // Todos os Masters ou nenhum: um registro parcial seguraria o aviso dos demais por 24 h.
        return $this->envios->emTransacao(function () use ($enviados): int {
            $avisados = 0;
            foreach ($this->usuarios->findComPapelGlobal('ROLE_SUPER_ADMIN') as $master) {
                $email = (string) $master->getEmail();
                if (!$master->isActive() || $email === '') {
                    continue;
                }
                $this->carteiro->enviar($this->mensagem($master, $email, $enviados));
                ++$avisados;
            }

            return $avisados;
        });
    }

    private function mensagem(User $master, string $email, int $enviados): MensagemDeEmail
    {
        $params = ['enviados' => $enviados, 'limite' => CotaDeEnvio::TROCAR_DE_SERVICO_ACIMA_DE];
        $paragrafos = [
            $this->tradutor->trans('comercial.aviso_troca.corpo', $params),
            $this->tradutor->trans('comercial.aviso_troca.fecho'),
        ];

        return new MensagemDeEmail(
            para: $email,
            assunto: $this->tradutor->trans('comercial.aviso_troca.assunto'),
            corpoHtml: implode('', array_map(static fn (string $p): string => '<p>' . htmlspecialchars($p, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '</p>', $paragrafos)),
            tipo: TipoDeEnvio::Transacional,
            origem: self::ORIGEM,
            nome: $master->getFullName(),
            titulo: $this->tradutor->trans('comercial.aviso_troca.titulo'),
            nomeDoRemetente: 'BlueJus',
            motivoDoEnvio: $this->tradutor->trans('comercial.aviso_troca.motivo_do_envio'),
        );
    }
}
