<?php

declare(strict_types=1);

namespace App\Calculo\UseCase;

use App\AtualizacaoMonetaria\Repository\IndiceMonetarioRepository;
use App\Calculo\DTO\CalculoInput;
use App\Calculo\DTO\ErroDoCalculo;
use App\Calculo\DTO\ResultadoDoCalculo;
use App\Calculo\DTO\ValorDoCalculo;
use App\Calculo\Service\CalculadoraDeAtualizacao;
use App\Calculo\Service\SeriesDoCalculo;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Valida a entrada da tela (mesmas regras e mensagens do desenho 14) e calcula. Não grava nada.
 *
 * Fluxo: Controller → CalculoInput → este caso de uso → CalculadoraDeAtualizacao. As validações de
 * "valor sem data" do campo que o usuário ainda está digitando ficam na tela, que é quem conhece esse rascunho.
 */
final class CalcularAtualizacaoUseCase
{
    public const MAX_VALORES = 1000;
    public const MAX_ABATIMENTOS = 200;

    public function __construct(
        private readonly IndiceMonetarioRepository $indices,
        private readonly CalculadoraDeAtualizacao $calculadora,
        private readonly ClockInterface $relogio,
        private readonly TranslatorInterface $tradutor,
    ) {
    }

    /** @return ErroDoCalculo|ResultadoDoCalculo o erro é o primeiro que impede o cálculo */
    public function executar(CalculoInput $in): ErroDoCalculo|ResultadoDoCalculo
    {
        $erro = $this->validar($in);
        if ($erro !== null) {
            return $erro;
        }

        $series = new SeriesDoCalculo($this->indices->carregarTabela());
        if (($in->indice !== 'nenhum' || $in->juros === 'legal') && !$series->temDados()) {
            return new ErroDoCalculo($this->t('erro.sem_indices'));
        }

        return $this->calculadora->calcular($in, $series, $this->relogio->now()->setTimezone(new \DateTimeZone('America/Sao_Paulo'))->format('Y-m-d'));
    }

    private function validar(CalculoInput $in): ?ErroDoCalculo
    {
        if (\count($in->valores) > self::MAX_VALORES || \count($in->abatimentos) > self::MAX_ABATIMENTOS) {
            return new ErroDoCalculo($this->t('erro.muitos_valores', ['max' => self::MAX_VALORES]));
        }
        if (array_filter($in->valores, static fn (ValorDoCalculo $v): bool => $v->completo()) === []) {
            return new ErroDoCalculo($this->t('erro.sem_valores'), 'nvV', $this->t('erro.ir_valores'));
        }
        if ($in->ate === '') {
            return new ErroDoCalculo($this->t('erro.sem_ate'), 'ate', $this->t('erro.ir_campo'));
        }
        if ($in->incidencia !== 'valores' && $in->dataJuros === '') {
            return new ErroDoCalculo($this->t($in->incidencia === 'fixa' ? 'erro.sem_data_juros_fixa' : 'erro.sem_data_juros_citacao'), 'dJur', $this->t('erro.ir_campo'));
        }

        $datas = [['ate', $in->ate], ['dJur', $in->dataJuros]];
        foreach ([...$in->valores, ...$in->abatimentos] as $v) {
            if ($v->completo()) {
                $datas[] = ['', $v->data];
            }
        }
        foreach ($datas as [$campo, $data]) {
            if ($data !== '' && !self::dataValida($data)) {
                return new ErroDoCalculo($this->t('erro.data_invalida', ['data' => mb_substr($data, 0, 12)]), $campo);
            }
        }

        return null;
    }

    private static function dataValida(string $data): bool
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $data, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /** @param array<string, mixed> $p */
    private function t(string $chave, array $p = []): string
    {
        return $this->tradutor->trans('calculo.' . $chave, $p);
    }
}
