<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\Exception\AcaoNaoPermitida;
use App\TrabalhoExterno\Exception\DadosInvalidos;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\LocalizadorDeVaga;
use App\TrabalhoExterno\Service\PoliticaDaVaga;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Registra cada canal usado no "Divulgar vaga(s)" (`regShare`, desenho 06 l.1077): soma a divulgação e
 * deixa no histórico "Divulgada por {canal} ({quem})". Só nas vagas abertas que o usuário pode
 * compartilhar; as demais ficam de fora em silêncio.
 */
final class RegistrarDivulgacaoUseCase
{
    public const CANAIS = ['WhatsApp', 'E-mail', 'LinkedIn', 'mensagem copiada', 'link'];

    public function __construct(
        private readonly LocalizadorDeVaga $localizador,
        private readonly PoliticaDaVaga $politica,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @param list<string> $codigos */
    public function executar(Contexto $ctx, array $codigos, string $canal): int
    {
        if ($ctx->previa) {
            throw new AcaoNaoPermitida('Na pré-visualização não é possível alterar nada.');
        }
        if (!in_array($canal, self::CANAIS, true)) {
            throw new DadosInvalidos('Canal desconhecido.');
        }
        $agora = new \DateTimeImmutable();
        $pedidos = array_flip($codigos);
        $n = 0;
        foreach ($this->localizador->visiveis($ctx, $agora) as $v) {
            if (isset($pedidos[$v->getCodigo()]) && $this->politica->podeCompartilhar($ctx, $v, $agora)) {
                $v->registrarDivulgacao($canal, $ctx->autor()->nome, $agora);
                ++$n;
            }
        }
        $this->em->flush();

        return $n;
    }
}
