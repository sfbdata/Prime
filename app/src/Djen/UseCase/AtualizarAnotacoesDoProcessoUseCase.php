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
     * @param ?int      $processoId       o processo cadastrado com este número, se houver
     * @param list<int> $processosOcultos {@see \App\Pasta\Service\AcessoAPasta::idsDosProcessosOcultos()}: o processo
     *                                    cujas pastas são todas ocultas para a pessoa não abre para ela (K3)
     *
     * @throws AcompanhamentoInvalidoException com a mensagem da tela
     */
    public function executar(Tenant $tenant, User $usuario, string $numero, string $observacao, string $area, string $fase, string $email, ?int $processoId, array $processosOcultos): void
    {
        if ($processoId !== null && \in_array($processoId, $processosOcultos, true)) {
            throw new AcompanhamentoInvalidoException('Processo não encontrado.');
        }
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
            ?? new ProcessoAcompanhado($tenant, $digitos, $processoId === null, $usuario);
        $linha->anotar($email, $observacao, $area, $fase);
        $this->acompanhados->salvar($linha);
    }
}
