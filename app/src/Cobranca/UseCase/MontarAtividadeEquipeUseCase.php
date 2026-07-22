<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\AtividadeEquipeOutput;
use App\Cobranca\DTO\AtividadePessoaOutput;
use App\Cobranca\DTO\DesfechoContatoOutput;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Repository\EventoHistoricoRepository;
use App\Cobranca\Repository\UsuarioCobrancaRepository;
use App\Cobranca\Service\PastilhasDeContato;
use App\Entity\Tenant\Tenant;

/**
 * Leitura: monta a aba Atividade da Central de Acompanhamento (spec `cobranca-central-acompanhamento.md`,
 * Fatia 1). Read-only — não persiste nada, não cria evento, não muda estado.
 *
 * História: o gestor do escritório quer responder "o que a equipe fez esta semana". Hoje o painel é
 * financeiro e por carteira; não existe visão POR PESSOA. Esta é a matéria-prima que já se registra
 * (`cobranca_evento_historico`), organizada por quem fez.
 *
 * Volume e efetividade lado a lado, sem nota única e sem ranking (decisão do dono): contatos é toda
 * tentativa, atendidos é quem de fato falou com o devedor. Nada de score, semáforo ou R$ por pessoa.
 *
 * Três regras dão o formato da tabela:
 *
 * 1. Quem NÃO trabalhou aparece zerado — a ausência é justamente o que o gestor foi procurar, então a
 *    lista parte da equipe elegível, não dos eventos.
 * 2. Quem trabalhou e não está mais na equipe continua na tabela (ao final, ordenado junto): esconder
 *    faria o total do setor não bater com a soma das linhas.
 * 3. Evento órfão (`usuario_id` nulo, herança do `onDelete: SET NULL`) vai para a linha "Sem
 *    responsável", NUNCA atribuído a alguém.
 *
 * A agregação inteira é uma consulta no banco (GROUP BY + COUNT FILTER); aqui só se costura o resultado
 * com a lista da equipe. Período e carteira chegam resolvidos e tenant-safe do controller.
 */
final class MontarAtividadeEquipeUseCase
{
    public function __construct(
        private readonly EventoHistoricoRepository $eventoRepository,
        private readonly UsuarioCobrancaRepository $usuarioRepository,
        private readonly PastilhasDeContato $pastilhas,
    ) {
    }

    public function executar(
        Tenant $tenant,
        \DateTimeImmutable $inicio,
        \DateTimeImmutable $fimExclusivo,
        ?Carteira $carteira = null,
    ): AtividadeEquipeOutput {
        $agregado = $this->eventoRepository->agregarAtividadePorUsuario($tenant, $inicio, $fimExclusivo, $carteira);

        // "Quantos contatos e por qual meio" (spec §4): a faixa é do SETOR e respeita os mesmos filtros.
        $canais = $this->pastilhas->canais($this->eventoRepository->contarPayloadDeContatoDoSetor(
            $tenant,
            PastilhasDeContato::CHAVE_CANAL,
            $inicio,
            $fimExclusivo,
            $carteira,
        ));

        /** @var array<int, array<string, mixed>> $porUsuario */
        $porUsuario = [];
        $orfao = null;
        foreach ($agregado as $linha) {
            if ($linha['usuarioId'] === null) {
                $orfao = $linha;

                continue;
            }

            $porUsuario[$linha['usuarioId']] = $linha;
        }

        $pessoas = [];
        foreach ($this->usuarioRepository->ativosComAcessoAoModulo($tenant) as $elegivel) {
            $linha = $porUsuario[$elegivel['id']] ?? null;
            unset($porUsuario[$elegivel['id']]);

            $pessoas[] = $linha === null
                ? AtividadePessoaOutput::zerada($elegivel['id'], $elegivel['nome'])
                : $this->linhaDaPessoa($linha, $elegivel['nome']);
        }

        // Sobra do agregado: quem registrou trabalho no período mas não está na equipe elegível hoje
        // (perdeu o módulo, foi desativado, mudou de escritório). O trabalho dele não pode sumir.
        //
        // Mas só entra quem de fato TRABALHOU. Depois do §5.1, quem apenas rodou uma importação zera as
        // quatro colunas e a última ação: viraria uma linha "0 0 0 0 —" idêntica à de um colega da
        // equipe, sem nada que explique a presença dela e sem somar nada ao total. A lista é a equipe
        // (spec §4) mais quem produziu número — não todo id que passou pela tabela de eventos.
        foreach ($porUsuario as $linha) {
            if (!$this->produziuNumero($linha)) {
                continue;
            }

            $pessoas[] = $this->linhaDaPessoa($linha, $this->nomeDeQuemSaiu($linha));
        }

        // Ordem alfabética de gente, não de bytes: o container roda com LC_COLLATE=C, onde "Élida"
        // cairia depois de "Zulmira". O Collator resolve acento e caixa pelas regras do português.
        $collator = new \Collator('pt_BR');
        usort($pessoas, static fn (AtividadePessoaOutput $a, AtividadePessoaOutput $b): int => (int) $collator->compare($a->nome, $b->nome));

        // "Sem responsável" fica sempre por último — é uma linha de sobra, não uma pessoa da equipe.
        if ($orfao !== null) {
            $pessoas[] = $this->linhaDaPessoa($orfao, AtividadePessoaOutput::SEM_RESPONSAVEL, semResponsavel: true);
        }

        return $this->comTotais($pessoas, $canais);
    }

    /**
     * @param array<string, mixed> $linha
     */
    private function linhaDaPessoa(array $linha, string $nome, bool $semResponsavel = false): AtividadePessoaOutput
    {
        /** @var ?\DateTimeImmutable $ultimaAcao */
        $ultimaAcao = $linha['ultimaAcao'];

        return new AtividadePessoaOutput(
            usuarioId: $semResponsavel ? null : (int) $linha['usuarioId'],
            nome: $nome,
            contatos: (int) $linha['contatos'],
            atendidos: (int) $linha['atendidos'],
            acordos: (int) $linha['acordos'],
            baixas: (int) $linha['baixas'],
            ultimaAcao: $ultimaAcao,
            semResponsavel: $semResponsavel,
        );
    }

    /**
     * A linha tem algo a mostrar? Vale só para a SOBRA do agregado — membro da equipe elegível aparece
     * zerado por decisão da spec (§4: a ausência é a informação que o gestor foi buscar).
     *
     * @param array<string, mixed> $linha
     */
    private function produziuNumero(array $linha): bool
    {
        return (int) $linha['contatos'] > 0
            || (int) $linha['acordos'] > 0
            || (int) $linha['baixas'] > 0
            || $linha['ultimaAcao'] !== null;
    }

    /**
     * O nome vem do LEFT JOIN da agregação. Só cai no genérico se o usuário sumiu da tabela `user` —
     * o que a FK `ON DELETE SET NULL` torna improvável, mas uma linha sem rótulo seria pior.
     *
     * @param array<string, mixed> $linha
     */
    private function nomeDeQuemSaiu(array $linha): string
    {
        $nome = $linha['usuarioNome'];

        return \is_string($nome) && $nome !== '' ? $nome : 'Usuário removido';
    }

    /**
     * @param list<AtividadePessoaOutput> $pessoas
     * @param list<DesfechoContatoOutput> $canais
     */
    private function comTotais(array $pessoas, array $canais): AtividadeEquipeOutput
    {
        $contatos = 0;
        $atendidos = 0;
        $acordos = 0;
        $baixas = 0;
        $ultimaAcao = null;

        foreach ($pessoas as $pessoa) {
            $contatos += $pessoa->contatos;
            $atendidos += $pessoa->atendidos;
            $acordos += $pessoa->acordos;
            $baixas += $pessoa->baixas;

            if ($pessoa->ultimaAcao !== null && ($ultimaAcao === null || $pessoa->ultimaAcao > $ultimaAcao)) {
                $ultimaAcao = $pessoa->ultimaAcao;
            }
        }

        return new AtividadeEquipeOutput($pessoas, $contatos, $atendidos, $acordos, $baixas, $ultimaAcao, $canais);
    }
}
