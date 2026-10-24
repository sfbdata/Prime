<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\Shared\Armazenamento\ChaveDeArquivo;
use App\TrabalhoExterno\Entity\DespachoDemanda;
use App\TrabalhoExterno\Enum\NaturezaDoDespacho;
use App\TrabalhoExterno\Exception\AcaoNaoPermitida;
use App\TrabalhoExterno\Exception\DadosInvalidos;
use App\TrabalhoExterno\Repository\DemandaExternaRepository;
use App\TrabalhoExterno\Service\ArmazenadorDeDocumentos;
use App\TrabalhoExterno\Service\AvisosDoTrabalhoExterno;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\LocalizadorDeDemanda;
use App\TrabalhoExterno\Service\PoliticaDaDemanda;
use App\TrabalhoExterno\Service\PrazoDoDespacho;
use App\TrabalhoExterno\Service\TransacaoDoDespacho;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * "Despachar" no DESPACHO do Resumo (desenho 06 de 24/10, `dpEnviar`, l.1394): só o Master. O texto tem de ter
 * ao menos 3 caracteres ("Escreva o despacho"); a natureza é opcional; sem prazo vale o padrão (hoje mais 5 dias
 * úteis, pulando os feriados). Os anexos são arquivos de verdade, gravados na mesma transação do despacho
 * ({@see TransacaoDoDespacho}). O registro "Novo despacho da Central BlueJus{ · natureza}." avisa as partes.
 */
final class DespacharNaDemandaUseCase
{
    public const TEXTO_VAZIO = 'O texto do despacho está vazio.';

    public function __construct(
        private readonly LocalizadorDeDemanda $localizador,
        private readonly PoliticaDaDemanda $politica,
        private readonly ArmazenadorDeDocumentos $documentos,
        private readonly DemandaExternaRepository $repositorio,
        private readonly AvisosDoTrabalhoExterno $avisos,
        private readonly PrazoDoDespacho $prazos,
        private readonly TransacaoDoDespacho $transacao,
    ) {
    }

    /** @param list<UploadedFile> $arquivos */
    public function executar(Contexto $ctx, string $codigo, string $texto, string $natureza, string $prazo, array $arquivos, ?\DateTimeImmutable $hoje = null): DespachoDemanda
    {
        [$demanda, $ctx] = $this->localizador->localizar($ctx, $codigo);
        if (!$this->politica->podeDespachar($ctx, $demanda)) {
            throw new AcaoNaoPermitida('Só a Central BlueJus publica despachos.');
        }
        if (mb_strlen(trim($texto)) < 3) {
            throw new DadosInvalidos(self::TEXTO_VAZIO);
        }
        $nat = $natureza === '' ? null : (NaturezaDoDespacho::tryFrom($natureza) ?? throw new DadosInvalidos('Natureza do despacho desconhecida.'));
        $hoje ??= new \DateTimeImmutable('today');
        $data = $prazo === '' ? $this->prazos->padrao($hoje) : self::data($prazo);

        /** @var \ArrayObject<int, ChaveDeArquivo> $gravados */
        $gravados = new \ArrayObject();

        return $this->transacao->executar(
            function () use ($ctx, $demanda, $texto, $nat, $data, $arquivos, $gravados): DespachoDemanda {
                $despacho = $demanda->despachar($ctx->autor(), $texto, $nat, $data);
                $despacho->usarCodigo($this->repositorio->codigoDeDespachoLivre($despacho->getCodigo()));
                if ($arquivos !== []) {
                    $this->documentos->anexar($demanda, $arquivos, $ctx->autor(), $despacho, $gravados);
                }
                $this->avisos->andamentoDaDemanda($ctx, $demanda);
                $this->repositorio->salvar($demanda);

                return $despacho;
            },
            static fn (): array => array_values($gravados->getArrayCopy()),
        );
    }

    private static function data(string $prazo): \DateTimeImmutable
    {
        $data = \DateTimeImmutable::createFromFormat('!Y-m-d', $prazo);
        if ($data === false || $data->format('Y-m-d') !== $prazo) {
            throw new DadosInvalidos('Prazo do despacho inválido.');
        }

        return $data;
    }
}
