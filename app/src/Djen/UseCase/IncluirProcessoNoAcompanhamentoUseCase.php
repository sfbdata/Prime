<?php

declare(strict_types=1);

namespace App\Djen\UseCase;

use App\Djen\Entity\ProcessoAcompanhado;
use App\Djen\Exception\AcompanhamentoInvalidoException;
use App\Djen\Repository\ProcessoAcompanhadoRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Processo\Entity\Processo;
use App\Processo\Repository\ProcessoRepository;

/**
 * Inclui um número no acompanhamento do Push (formulário "Inclusão de processos para acompanhamento" e
 * "Incluir no acompanhamento" do Relatório, desenho 11).
 *
 * Processo já cadastrado e pausado: retoma o monitoramento dele (a linha de anotações fica só com as
 * anotações). Sem processo cadastrado: guarda o número para a sincronização consultar. Número que já é
 * acompanhado é recusado.
 */
final class IncluirProcessoNoAcompanhamentoUseCase
{
    public function __construct(
        private readonly ProcessoRepository $processos,
        private readonly ProcessoAcompanhadoRepository $acompanhados,
    ) {
    }

    /**
     * @throws AcompanhamentoInvalidoException com a mensagem da tela
     */
    public function executar(Tenant $tenant, User $usuario, string $numero, string $emailAlternativo = '', string $observacao = ''): void
    {
        $digitos = (string) preg_replace('/\D/', '', $numero);
        if (\strlen($digitos) !== 20) {
            throw new AcompanhamentoInvalidoException('Informe o número completo do processo (20 dígitos, padrão CNJ).');
        }
        $email = trim($emailAlternativo);
        if ($email !== '' && filter_var($email, \FILTER_VALIDATE_EMAIL) === false) {
            throw new AcompanhamentoInvalidoException('E-mail alternativo inválido.');
        }
        $observacao = trim($observacao);
        if (mb_strlen($observacao) > ProcessoAcompanhado::LIMITE_OBSERVACAO) {
            throw new AcompanhamentoInvalidoException('A observação aceita até ' . ProcessoAcompanhado::LIMITE_OBSERVACAO . ' caracteres.');
        }

        $processo = $this->processoCadastrado($tenant, $digitos);
        if ($processo?->getDataBaixa() !== null) {
            throw new AcompanhamentoInvalidoException('Este processo tem baixa e não pode ser acompanhado.');
        }
        $linha = $this->acompanhados->findOneDoTenantPorNumero($tenant, $digitos);
        $jaAcompanhado = $processo !== null
            ? $processo->monitoramentoAtivo()
            : $linha?->isAcompanhando() === true;
        if ($jaAcompanhado) {
            throw new AcompanhamentoInvalidoException('Este processo já está sendo acompanhado.');
        }

        $linha ??= new ProcessoAcompanhado($tenant, $digitos, $processo === null, $usuario);
        $linha->limparExclusao();
        if ($processo !== null) {
            $processo->retomarMonitoramento($usuario, new \DateTimeImmutable());
        } else {
            $linha->acompanhar();
        }
        $linha->anotar($email !== '' ? $email : $linha->getEmailAlternativo(), $observacao !== '' ? $observacao : $linha->getObservacao(), $linha->getArea(), $linha->getFase());
        $this->acompanhados->salvar($linha);
    }

    private function processoCadastrado(Tenant $tenant, string $digitos): ?Processo
    {
        return $this->processos->findByNumeroProcessoDoTenant($digitos, $tenant);
    }
}
