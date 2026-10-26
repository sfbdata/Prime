<?php

declare(strict_types=1);

namespace App\Djen\UseCase;

use App\Djen\Entity\ProcessoAcompanhado;
use App\Djen\Exception\AcompanhamentoInvalidoException;
use App\Djen\Repository\ProcessoAcompanhadoRepository;
use App\Djen\Service\ClassificacaoDoProcesso;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * Grava observação, área, fase e e-mail alternativo de um número (lápis da linha, desenho 11). A linha de
 * anotações nasce na primeira gravação; um processo cadastrado ganha a linha sem passar a ser "incluído na mão".
 */
final class AtualizarAnotacoesDoProcessoUseCase
{
    public function __construct(private readonly ProcessoAcompanhadoRepository $acompanhados)
    {
    }

    /**
     * @throws AcompanhamentoInvalidoException com a mensagem da tela
     */
    public function executar(Tenant $tenant, User $usuario, string $numero, string $observacao, string $area, string $fase, string $email, bool $temProcessoCadastrado): void
    {
        $digitos = (string) preg_replace('/\D/', '', $numero);
        if (\strlen($digitos) !== 20) {
            throw new AcompanhamentoInvalidoException('Número de processo inválido.');
        }
        $observacao = trim($observacao);
        if (mb_strlen($observacao) > ProcessoAcompanhado::LIMITE_OBSERVACAO) {
            throw new AcompanhamentoInvalidoException('A observação aceita até ' . ProcessoAcompanhado::LIMITE_OBSERVACAO . ' caracteres.');
        }
        if (!ClassificacaoDoProcesso::areaValida($area)) {
            throw new AcompanhamentoInvalidoException('Área inválida.');
        }
        if (!ClassificacaoDoProcesso::faseValida($fase)) {
            throw new AcompanhamentoInvalidoException('Fase inválida.');
        }
        $email = trim($email);
        if ($email !== '' && filter_var($email, \FILTER_VALIDATE_EMAIL) === false) {
            throw new AcompanhamentoInvalidoException('E-mail alternativo inválido.');
        }

        $linha = $this->acompanhados->findOneDoTenantPorNumero($tenant, $digitos)
            ?? new ProcessoAcompanhado($tenant, $digitos, !$temProcessoCadastrado, $usuario);
        $linha->anotar($email, $observacao, $area, $fase);
        $this->acompanhados->salvar($linha);
    }
}
