<?php

declare(strict_types=1);

namespace App\Calculo\UseCase;

use App\Calculo\DTO\CalculoInput;
use App\Calculo\DTO\ErroDoCalculo;
use App\Calculo\DTO\ResultadoDoCalculo;
use App\Calculo\Entity\CalculoEmitido;
use App\Calculo\Repository\CalculoEmitidoRepository;
use App\Calculo\Service\ApresentacaoDoCalculo;
use App\Calculo\Service\GeradorDePdfDoCalculo;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Service\GeradorDePdf;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/** Calcula e devolve o demonstrativo em PDF (conteúdo e nome do arquivo). Nada é gravado. */
final class GerarPdfDoCalculoUseCase
{
    public function __construct(
        private readonly CalcularAtualizacaoUseCase $calcular,
        private readonly ConsultarStatusDosIndicesUseCase $status,
        private readonly ApresentacaoDoCalculo $apresentacao,
        private readonly GeradorDePdfDoCalculo $gerador,
        private readonly Environment $twig,
        private readonly ClockInterface $relogio,
        private readonly TranslatorInterface $tradutor,
        private readonly GeradorDePdf $logos,
        private readonly CalculoEmitidoRepository $emitidos,
    ) {
    }

    /** @return ErroDoCalculo|array{conteudo: string, nome: string} */
    public function executar(CalculoInput $in, User $usuario, Tenant $tenant): ErroDoCalculo|array
    {
        $resultado = $this->calcular->executar($in);
        if ($resultado instanceof ErroDoCalculo) {
            return $resultado;
        }

        $agora = $this->relogio->now()->setTimezone(new \DateTimeZone('America/Sao_Paulo'));
        $codigo = $this->codigoNovo();
        $t = fn (string $chave, array $p = []): string => $this->tradutor->trans('calculo.pdf.' . $chave, $p);
        $html = $this->twig->render('calculo/pdf.html.twig', $this->contexto($in, $resultado, $usuario, $tenant, $t));

        $emitidoEm = $agora->format('d/m/y, H:i');
        $pdf = $this->gerador->gerar(
            $html,
            $emitidoEm,
            $t('cabecalho'),
            $t('rodape', ['codigo' => $codigo]),
        );
        $this->emitidos->salvar(new CalculoEmitido(
            $tenant,
            $usuario,
            $codigo,
            $agora, // hora de Brasília, igual à impressa no cabeçalho do PDF
            new \DateTimeImmutable($resultado->ate),
            number_format($resultado->total, 2, '.', ''),
            hash('sha256', $pdf),
        ));
        $nomeProcesso = (string) preg_replace('/[^\w.-]+/u', '-', $in->processo !== '' ? $in->processo : 'BlueJus');

        return ['conteudo' => $pdf, 'nome' => $t('nome_arquivo') . ' ' . $nomeProcesso . ' ' . $agora->format('d-m-Y') . '.pdf'];
    }

    /** Código inédito: o índice único é a garantia, a checagem evita tropeçar nele. */
    private function codigoNovo(): string
    {
        do {
            $codigo = GeradorDePdfDoCalculo::codigoDeVerificacao();
        } while ($this->emitidos->existeCodigo($codigo));

        return $codigo;
    }

    /** @param \Closure(string, array=): string $t */
    private function contexto(CalculoInput $in, ResultadoDoCalculo $resultado, User $usuario, Tenant $tenant, \Closure $t): array
    {
        $mes = static fn (string $iso): string => ApresentacaoDoCalculo::mesAno($iso);
        $indice = $t($in->indice === 'nenhum' ? 'indice_sem' : ($in->indice === 'inpc' ? 'indice_inpc' : 'indice_oficial'));

        return [
            'escritorio' => $tenant->getName() ?: $t('escritorio_padrao'),
            'marcaDagua' => mb_strtolower(explode(' ', trim((string) $usuario->getFullName()))[0]),
            'entrada' => $in,
            'resultado' => $resultado,
            'ate' => ApresentacaoDoCalculo::data($resultado->ate),
            'mesAte' => $mes($resultado->ate),
            'indices' => array_map(fn ($l): string => $t('indice_periodo', ['indice' => $indice, 'de' => $mes($l->data), 'ate' => $mes($resultado->ate)]), $resultado->linhas),
            'resumo' => $this->apresentacao->resumo($in, $resultado),
            'criterioIndice' => $this->apresentacao->criterioDeIndice($in),
            'criterioJuros' => $this->apresentacao->criterioDeJuros($in),
            'dataJuros' => $this->apresentacao->dataDosJuros($in),
            'aviso' => $this->apresentacao->avisoDeProjecao($resultado),
            'consultadoEm' => $this->consultadoEm(),
            'logo' => $this->logos->logoEmbutido() ?? '',
        ];
    }

    private function consultadoEm(): string
    {
        $dica = $this->status->executar()['dica'];

        return preg_match('#(\d{2}/\d{2}/\d{4})#', $dica, $m) === 1 ? $m[1] : '';
    }
}
