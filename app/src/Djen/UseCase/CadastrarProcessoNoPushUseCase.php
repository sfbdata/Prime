<?php

declare(strict_types=1);

namespace App\Djen\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Processo\DTO\CadastroDoProcessoInput;
use App\Processo\DTO\DadosManuaisDoProcesso;
use App\Processo\DTO\ProcessoDoCadastro;
use App\Processo\Exception\DadosDoProcessoInvalidosException;
use App\Processo\Repository\ProcessoRepository;
use App\Processo\UseCase\CadastrarProcessoPelaPastaUseCase;

/**
 * "Cadastrar novo (CNJ)" do modal "Vincular processo" do Push (desenho 11, spec `docs/specs/push-vincular-processo.md`).
 * O cadastro é o mesmo da janela da pasta (número já existente devolve o existente); aqui o processo nasce sem pasta,
 * com o que o desenho pede além do input (valor da causa, jurisdição e as perguntas Sim/Não) como dados manuais,
 * e entra no monitoramento do Push.
 */
final class CadastrarProcessoNoPushUseCase
{
    public function __construct(
        private readonly CadastrarProcessoPelaPastaUseCase $cadastrar,
        private readonly IncluirProcessoCadastradoNoPushUseCase $incluir,
        private readonly ProcessoRepository $processos,
    ) {
    }

    /**
     * @param array<mixed>|null                                                                                                                      $consultaDoCnj
     * @param array{valorCausa?: string, jurisdicao?: string, segredo?: ?bool, digital?: ?bool, gratuita?: ?bool, tutela?: ?bool, prioridade?: ?bool} $extras
     *
     * @throws DadosDoProcessoInvalidosException
     */
    public function executar(Tenant $tenant, User $usuario, CadastroDoProcessoInput $input, ?array $consultaDoCnj = null, array $extras = []): ProcessoDoCadastro
    {
        $cadastro = $this->cadastrar->executar($input, $tenant, $usuario, $consultaDoCnj);
        $processo = $cadastro->processo;

        if (!$cadastro->jaExistia) {
            $manuais = new DadosManuaisDoProcesso(
                jurisdicao: self::texto($extras['jurisdicao'] ?? ''),
                valorCausa: self::valor($extras['valorCausa'] ?? ''),
                segredo: $extras['segredo'] ?? null,
                digital: $extras['digital'] ?? null,
                gratuita: $extras['gratuita'] ?? null,
                tutela: $extras['tutela'] ?? null,
                prioridade: $extras['prioridade'] ?? null,
            );
            if ($manuais->temAlgum()) {
                $processo->definirDadosManuais($manuais, $usuario);
            }
            $this->processos->save($processo, true);
        }

        $this->incluir->executar($tenant, $usuario, $processo);

        return $cadastro;
    }

    private static function texto(string $valor): ?string
    {
        $valor = trim($valor);

        return $valor === '' ? null : mb_substr($valor, 0, 255);
    }

    /** "R$ 1.234,56" vira "1234.56" (mesma regra do cartão do processo). */
    private static function valor(string $texto): ?string
    {
        $limpo = (string) preg_replace('/^R\$/u', '', (string) preg_replace('/\s+/u', '', $texto));
        if ($limpo === '') {
            return null;
        }
        if (preg_match('/^(\d{1,3}(\.\d{3})*|\d+)(,\d{1,2})?$/', $limpo) !== 1) {
            throw new DadosDoProcessoInvalidosException('Valor da causa inválido. Use R$ 0,00.');
        }
        [$inteiro, $centavos] = array_pad(explode(',', str_replace('.', '', $limpo)), 2, '0');
        $inteiro = ltrim($inteiro, '0') ?: '0';
        if (strlen($inteiro) > 13) {
            throw new DadosDoProcessoInvalidosException('Valor da causa inválido. Use R$ 0,00.');
        }

        return $inteiro . '.' . str_pad($centavos, 2, '0');
    }
}
