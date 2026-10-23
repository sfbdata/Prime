<?php

declare(strict_types=1);

namespace App\Comercial\Campanha;

use App\Comercial\DTO\MensagemDaCampanha;
use App\Comercial\Exception\CampanhaRecusada;
use App\Comercial\Repository\ImagemDaCampanhaRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * A imagem da mensagem do modo simples (spec `comercial-e-emails.md` §12, F3i): a mensagem guarda só o token; aqui ele
 * vira o endereço público absoluto e a validade é conferida e renovada.
 *
 *   - confirmação, teste, prévia e salvar modelo recusam o token desconhecido ou vencido ({@see self::EXPIRADA});
 *   - cada execução de uma campanha (a confirmação e cada volta do expansor, inclusive a recorrente) empurra a
 *     validade para 1 ano depois do envio e marca o uso; salvar um modelo com ela empurra para 1 ano depois do salvamento.
 *
 * No envio a imagem vencida some do e-mail em vez de travar o lote (o efeito esperado da spec: o e-mail antigo perde a
 * imagem depois de 1 ano).
 */
final class ImagensDaCampanha
{
    public const EXPIRADA = 'A imagem expirou; envie de novo.';
    public const VALIDADE_DO_USO = '+1 year';
    public const VALIDADE_SEM_USO = '+30 days';
    public const ROTA = 'comercial_imagem_publica';

    public function __construct(
        private readonly ImagemDaCampanhaRepository $imagens,
        private readonly UrlGeneratorInterface $urls,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function exigirVigente(MensagemDaCampanha $m, ?\DateTimeImmutable $agora = null): void
    {
        if ($m->imagem !== '' && $this->imagens->vigente($m->imagem, $agora ?? $this->relogio->now()) === null) {
            throw new CampanhaRecusada(self::EXPIRADA);
        }
    }

    /** O envio (confirmação ou execução do expansor): 1 ano a partir de agora, e o uso marcado. */
    public function renovarPeloEnvio(MensagemDaCampanha $m, \DateTimeImmutable $agora): void
    {
        if ($m->imagem !== '') {
            $this->imagens->prolongar($m->imagem, $agora->modify(self::VALIDADE_DO_USO), $agora);
        }
    }

    /** O modelo salvo: 1 ano a partir do salvamento. */
    public function renovarPeloModelo(MensagemDaCampanha $m, \DateTimeImmutable $agora): void
    {
        if ($m->imagem !== '') {
            $this->imagens->prolongar($m->imagem, $agora->modify(self::VALIDADE_DO_USO));
        }
    }

    /** O endereço público absoluto da imagem vigente (no worker, com o host do `DEFAULT_URI`); null sem imagem. */
    public function url(string $token): ?string
    {
        $imagem = $token === '' ? null : $this->imagens->vigente($token, $this->relogio->now());

        return $imagem === null ? null : $this->urls->generate(self::ROTA, ['token' => $imagem->getToken(), 'ext' => $imagem->extensao()], UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
