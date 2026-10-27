<?php

declare(strict_types=1);

namespace App\Recompensa\UseCase;

use App\Entity\Auth\User;
use App\Indicacao\Repository\IndicacaoRepository;
use App\Recompensa\DTO\SalvarDadosDeRecebimentoInput;
use App\Recompensa\Entity\DadosDeRecebimentoDeRecompensa;
use App\Recompensa\Exception\IndicadorNaoEncontrado;
use App\Recompensa\Exception\RecompensaRecusada;
use App\Recompensa\Repository\DadosDeRecebimentoDeRecompensaRepository;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * "Dados para pagamento" do indicador: nome, CPF ou CNPJ e chave PIX (menu ⋮; spec perfis-1.2-vs-25-10, F4). O sistema
 * guarda o valor bruto e não calcula imposto; este cadastro é o que o contador usa.
 *
 * Quem dispara: o Master (ROLE_SUPER_ADMIN no controller). Dado de plataforma, sem tenant: o indicador é qualquer usuário
 * que já indicou; o id vem da URL e é conferido contra as indicações.
 * Fluxo principal: cria ou atualiza o cadastro único do indicador, sob a trava das indicações dele (sem duas linhas).
 * Fluxos alternativos: indicador inexistente (404); nome vazio, documento inválido ou chave longa (422).
 * Auditoria: DadosDeRecebimentoDeRecompensa é Auditavel; cada mudança entra na trilha do Master.
 */
final class SalvarDadosDeRecebimentoUseCase
{
    public function __construct(
        private readonly DadosDeRecebimentoDeRecompensaRepository $dados,
        private readonly IndicacaoRepository $indicacoes,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $relogio,
        private readonly EscopoDeAuditoriaDePlataforma $escopoDePlataforma,
    ) {
    }

    /**
     * @throws IndicadorNaoEncontrado
     * @throws RecompensaRecusada
     */
    public function executar(SalvarDadosDeRecebimentoInput $input): void
    {
        if ($this->indicacoes->contarDoUsuario($input->userId) === 0) {
            throw new IndicadorNaoEncontrado('Indicador não encontrado.');
        }
        DadosDeRecebimentoDeRecompensa::normalizar($input->nome, $input->documento, $input->chavePix);
        $usuario = $this->em->find(User::class, $input->userId) ?? throw new IndicadorNaoEncontrado('Indicador não encontrado.');
        $agora = $this->relogio->now();

        $this->escopoDePlataforma->executar(fn () => $this->indicacoes->emTransacaoTravada($input->userId, function () use ($input, $usuario, $agora): void {
            $existente = $this->dados->buscarDoIndicador($input->userId);
            if ($existente === null) {
                $this->dados->salvar(new DadosDeRecebimentoDeRecompensa($usuario, $input->nome, $input->documento, $input->chavePix, $input->ator, $agora), flush: true);

                return;
            }
            $existente->atualizar($input->nome, $input->documento, $input->chavePix, $input->ator, $agora);
            $this->dados->salvar($existente, flush: true);
        }));
    }
}
