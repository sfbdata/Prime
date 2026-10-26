<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\Shared\Armazenamento\ChaveDeArquivo;
use App\TrabalhoExterno\Entity\DemandaExterna;
use App\TrabalhoExterno\Exception\AcaoNaoPermitida;
use App\TrabalhoExterno\Exception\DadosInvalidos;
use App\TrabalhoExterno\Exception\TransicaoInvalida;
use App\TrabalhoExterno\Repository\DemandaExternaRepository;
use App\TrabalhoExterno\Service\ApresentadorDoPagamento;
use App\TrabalhoExterno\Service\ArmazenadorDeDocumentos;
use App\TrabalhoExterno\Service\AvisosDoTrabalhoExterno;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\LocalizadorDeDemanda;
use App\TrabalhoExterno\Service\PoliticaDaDemanda;
use App\TrabalhoExterno\Service\TransacaoDoComprovante;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * "Já paguei · enviar comprovante" da aba Pagamento (desenho 25/10, `pg.onComp`): só o Solicitante da própria demanda,
 * uma vez, enquanto não pago, e só com proposta aceita (é dela que sai o valor). O arquivo (PDF ou imagem, 20 MB) é
 * gravado na mesma transação do registro "💳 Comprovante de pagamento PIX enviado ({arquivo}). Aguardando
 * confirmação." (que avisa as partes, menos o Convidado).
 * A demanda é travada (`FOR UPDATE`) dentro da transação antes de conferir "já pago" e "já enviado"; o índice único
 * parcial do banco é a segunda barreira, e o arquivo de quem perde a corrida sai do storage.
 * Alternativos: outro perfil → AcaoNaoPermitida (403); outro escritório → DemandaNaoEncontrada (404); já pago, já
 * enviado, sem aceite ou fora das situações do pagamento → TransicaoInvalida (409); sem arquivo ou de tipo
 * errado → DadosInvalidos (422).
 */
final class EnviarComprovanteDePagamentoUseCase
{
    public function __construct(
        private readonly LocalizadorDeDemanda $localizador,
        private readonly PoliticaDaDemanda $politica,
        private readonly ArmazenadorDeDocumentos $documentos,
        private readonly DemandaExternaRepository $repositorio,
        private readonly AvisosDoTrabalhoExterno $avisos,
        private readonly TransacaoDoComprovante $transacao,
    ) {
    }

    public function executar(Contexto $ctx, string $codigo, ?UploadedFile $arquivo): DemandaExterna
    {
        [$demanda, $ctx] = $this->localizador->localizar($ctx, $codigo);
        if (!$this->politica->podeEnviarComprovante($ctx, $demanda)) {
            throw new AcaoNaoPermitida('Você não pode enviar o comprovante desta demanda.');
        }
        if (ApresentadorDoPagamento::valorDaDemanda($demanda) === null) {
            throw new TransicaoInvalida('O pagamento fica disponível quando uma proposta for aceita.');
        }
        if ($arquivo === null) {
            throw new DadosInvalidos('Nenhum arquivo enviado.');
        }

        /** @var \ArrayObject<int, ChaveDeArquivo> $gravados */
        $gravados = new \ArrayObject();

        return $this->transacao->executar(
            function () use ($ctx, $demanda, $arquivo, $gravados): DemandaExterna {
                // Travada e relida: dois envios (ou um envio e a confirmação) se enfileiram aqui, antes de gravar arquivo.
                $this->repositorio->travar($demanda);
                if (ApresentadorDoPagamento::valorDaDemanda($demanda) === null) {
                    throw new TransicaoInvalida('O pagamento fica disponível quando uma proposta for aceita.');
                }
                if ($demanda->getPagoEm() !== null) {
                    throw new TransicaoInvalida('O pagamento desta demanda já foi confirmado.');
                }
                if ($demanda->comprovante() !== null) {
                    throw new TransicaoInvalida('O comprovante desta demanda já foi enviado.');
                }
                $demanda->enviarComprovante($this->documentos->gravarComprovante($demanda, $arquivo, $ctx->autor(), $gravados), $ctx->autor());
                $this->avisos->andamentoDaDemanda($ctx, $demanda);
                $this->repositorio->salvar($demanda);

                return $demanda;
            },
            static fn (): array => array_values($gravados->getArrayCopy()),
        );
    }
}
