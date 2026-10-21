<?php

declare(strict_types=1);

namespace App\Master\UseCase;

use App\Master\DTO\AdicionarSubsecaoNaSeccionalInput;
use App\Master\Entity\SubsecaoOab;
use App\Master\Exception\SeccionalNaoEncontrada;
use App\Master\Exception\SeccionalRecusada;
use App\Master\Repository\SeccionalOabRepository;
use App\Master\Repository\SubsecaoOabRepository;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use Psr\Clock\ClockInterface;

/**
 * Adiciona o endereço de uma subseção da OAB a uma seccional (spec docs/specs/master-oab-estados-que-faltam.md,
 * "SUBSEÇÕES"; desenho 05, Perfis 1.2, VS 21.10.26, L487: "Adicionar endereço").
 *
 * Quem dispara: o Master, no formulário da janela da OAB, aberta pelo estado ou pela capital que falta
 * (ROLE_SUPER_ADMIN no controller).
 * Objetivo: guardar onde procurar a advocacia de um estado (as subseções além da sede), para o trabalho de expansão.
 * Pré-condições: a UF é uma das 27 (o controller recusa as outras com 404) e a linha da seccional existe.
 * Fluxo principal: carrega a seccional, exige cidade e endereço, completa o nome vazio com "Subseção <cidade>"
 * (desenho 05 L893: `x.nome || 'Subseção ' + cidade`), confere os tamanhos, cria a subseção com o autor e o momento,
 * e grava dentro do escopo de plataforma.
 * Fluxos alternativos: linha ausente é 404; cidade ou endereço vazios, cidade ou nome acima de 120, endereço acima
 * de 255 ou telefone acima de 40 são recusados com a mensagem (422) e nada é gravado.
 * Pós-condições: a subseção aparece na janela da OAB do estado e na da cidade, para todos os Masters.
 * Auditoria: SubsecaoOab é Auditavel; a linha do audit_log fica sem escritório (trilha do Master).
 */
final class AdicionarSubsecaoNaSeccionalUseCase
{
    public function __construct(
        private readonly SeccionalOabRepository $seccionais,
        private readonly SubsecaoOabRepository $subsecoes,
        private readonly ClockInterface $relogio,
        private readonly EscopoDeAuditoriaDePlataforma $escopoDePlataforma,
    ) {
    }

    public function executar(AdicionarSubsecaoNaSeccionalInput $input): SubsecaoOab
    {
        $seccional = $this->seccionais->buscarPorUf($input->uf) ?? throw new SeccionalNaoEncontrada(SeccionalNaoEncontrada::RECUSA);
        $cidade = $input->cidade ?? throw new SeccionalRecusada(SeccionalRecusada::RECUSA_CIDADE_OBRIGATORIA);
        $endereco = $input->endereco ?? throw new SeccionalRecusada(SeccionalRecusada::RECUSA_ENDERECO_OBRIGATORIO);
        $nome = $input->nome ?? 'Subseção ' . $cidade;
        $this->exigirTamanhos($cidade, $nome, $endereco, $input->telefone);

        $subsecao = new SubsecaoOab($seccional, $cidade, $nome, $endereco, $input->telefone, $input->ator, $this->relogio->now());
        $this->escopoDePlataforma->executar(fn () => $this->subsecoes->salvar($subsecao, flush: true));

        return $subsecao;
    }

    private function exigirTamanhos(string $cidade, string $nome, string $endereco, ?string $telefone): void
    {
        if (mb_strlen($cidade) > SubsecaoOab::CIDADE_MAXIMA) {
            throw new SeccionalRecusada(SeccionalRecusada::RECUSA_CIDADE_LONGA, ['limite' => SubsecaoOab::CIDADE_MAXIMA]);
        }

        if (mb_strlen($nome) > SubsecaoOab::NOME_MAXIMO) {
            throw new SeccionalRecusada(SeccionalRecusada::RECUSA_NOME_LONGO, ['limite' => SubsecaoOab::NOME_MAXIMO]);
        }

        if (mb_strlen($endereco) > SubsecaoOab::ENDERECO_MAXIMO) {
            throw new SeccionalRecusada(SeccionalRecusada::RECUSA_ENDERECO_LONGO, ['limite' => SubsecaoOab::ENDERECO_MAXIMO]);
        }

        if (mb_strlen((string) $telefone) > SubsecaoOab::TELEFONE_MAXIMO) {
            throw new SeccionalRecusada(SeccionalRecusada::RECUSA_TELEFONE_LONGO, ['limite' => SubsecaoOab::TELEFONE_MAXIMO]);
        }
    }
}
