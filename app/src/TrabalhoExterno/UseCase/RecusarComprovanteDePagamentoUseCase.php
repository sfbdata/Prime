<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\Shared\Armazenamento\RemocaoAposTransacao;
use App\TrabalhoExterno\Armazenamento\ChavesDeTrabalhoExterno;
use App\TrabalhoExterno\Entity\DemandaExterna;
use App\TrabalhoExterno\Enum\Perfil;
use App\TrabalhoExterno\Exception\AcaoNaoPermitida;
use App\TrabalhoExterno\Exception\DadosInvalidos;
use App\TrabalhoExterno\Exception\TransicaoInvalida;
use App\TrabalhoExterno\Repository\DemandaExternaRepository;
use App\TrabalhoExterno\Service\AvisosDoTrabalhoExterno;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\LocalizadorDeDemanda;

/**
 * "Recusar comprovante" da aba Pagamento (spec `trabalho-externo-recusar-comprovante.md`). Quem: só o Master, fora da
 * prévia (os demais levam 403). Motivo de 10 a 300 caracteres (422). A demanda é travada (`FOR UPDATE`) e relida antes
 * das conferências de estado (409): fora das situações do pagamento, já paga ou sem comprovante. Remove o documento do
 * comprovante (o arquivo só sai do storage DEPOIS do COMMIT, skill `armazenamento-de-arquivos`), grava o marco no
 * histórico, a mensagem de sistema do tipo Pagamento (sem o motivo) e avisa o solicitante com o motivo. O solicitante
 * pode enviar outro comprovante. Outro escritório: 404 (no localizador).
 */
final class RecusarComprovanteDePagamentoUseCase
{
    public const MOTIVO_MIN = 10;
    public const MOTIVO_MAX = 300;

    public function __construct(
        private readonly LocalizadorDeDemanda $localizador,
        private readonly DemandaExternaRepository $repositorio,
        private readonly AvisosDoTrabalhoExterno $avisos,
        private readonly RemocaoAposTransacao $remocao,
    ) {
    }

    public function executar(Contexto $ctx, string $codigo, string $motivo): DemandaExterna
    {
        [$demanda, $ctx] = $this->localizador->localizar($ctx, $codigo);

        if ($ctx->perfil !== Perfil::Master || $ctx->somenteLeitura()) {
            throw new AcaoNaoPermitida('Só o Master recusa o comprovante do pagamento.');
        }
        $motivo = trim($motivo);
        $tamanho = mb_strlen($motivo);
        if ($tamanho < self::MOTIVO_MIN || $tamanho > self::MOTIVO_MAX) {
            throw new DadosInvalidos(sprintf('Descreva o motivo com %d a %d caracteres.', self::MOTIVO_MIN, self::MOTIVO_MAX));
        }

        $chave = null;
        $resultado = $this->repositorio->comADemandaTravada($demanda, function () use ($ctx, $demanda, $motivo, &$chave): DemandaExterna {
            $comprovante = $demanda->comprovante()
                ?? throw new TransicaoInvalida('Esta demanda não tem comprovante para recusar.');
            // A chave sai ANTES de o documento deixar a demanda.
            $chave = ChavesDeTrabalhoExterno::documento($comprovante);
            $demanda->recusarComprovante($ctx->autor(), $motivo);
            $this->avisos->andamentoDaDemanda($ctx, $demanda);
            $this->repositorio->salvar($demanda);

            return $demanda;
        });

        // O COMMIT já aconteceu (fim do wrapInTransaction): só agora o arquivo sai do storage.
        if ($chave !== null) {
            $this->remocao->remover([$chave], 'comprovante recusado do Trabalho Externo ' . $demanda->getCodigo());
        }

        return $resultado;
    }
}
