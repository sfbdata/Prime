<?php

declare(strict_types=1);

namespace App\Voto\DTO;

use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Voto\Enum\CenarioDaAssembleia;
use App\Voto\Enum\EstadoDaAssembleia;
use App\Voto\Enum\PesoDoVoto;
use App\Voto\Service\CnpjDaEntidade;

/** A assembleia como o gestor a vê, com as pautas em ordem e os totais da lista de unidades. */
final readonly class AssembleiaNoPainel
{
    /**
     * @param list<PautaNoPainel> $pautas
     * @param string              $pesoApto soma do peso apto, 6 casas
     */
    public function __construct(
        public int $id,
        public string $titulo,
        public PesoDoVoto $peso,
        public EstadoDaAssembleia $estado,
        public string $criadaPorNome,
        public \DateTimeImmutable $criadaEm,
        public ?\DateTimeImmutable $publicadaEm,
        public bool $podeAlterarPautas,
        public bool $podeAlterarUnidades,
        public int $unidadesAptas,
        public int $unidadesInaptas,
        public string $pesoApto,
        public array $pautas,
        public ?\DateTimeImmutable $arquivadaEm = null,
        public ?string $arquivadaMotivo = null,
        public ?string $arquivadaPorNome = null,
        public ?string $entidade = null,
        public ?string $cnpj = null,
        public CenarioDaAssembleia $cenario = CenarioDaAssembleia::Condominio,
        public bool $simulacao = false,
        public ?string $rotuloUnidade = null,
        public ?string $rotuloBloco = null,
        /** O edital em PDF (nome que o gestor enviou, tamanho, sha256 e a chave do arquivo); nulos sem edital. */
        public ?string $editalNome = null,
        public ?int $editalTamanho = null,
        public ?string $editalSha256 = null,
        public ?ChaveDeArquivo $editalChave = null,
        /** O edital ainda pode ser anexado, trocado ou removido (nenhum resultado saiu e a votação não está arquivada). */
        public bool $editalAlteravel = true,
        /** Link da sala virtual do debate (Meet, Zoom, Teams...); nulo se a organização não informou. */
        public ?string $linkSalaVirtual = null,
    ) {
    }

    /** Nome do campo Unidade (padrão "Unidade"). */
    public function nomeDaUnidade(): string
    {
        return $this->rotuloUnidade ?? 'Unidade';
    }

    /** Nome do campo Bloco (padrão "Bloco"). */
    public function nomeDoBloco(): string
    {
        return $this->rotuloBloco ?? 'Bloco';
    }

    /**
     * A mesma assembleia com outro conjunto de pautas (a minuta de uma pauta só).
     *
     * @param list<PautaNoPainel> $pautas
     */
    public function comPautas(array $pautas): self
    {
        return new self(
            $this->id, $this->titulo, $this->peso, $this->estado, $this->criadaPorNome, $this->criadaEm, $this->publicadaEm,
            $this->podeAlterarPautas, $this->podeAlterarUnidades, $this->unidadesAptas, $this->unidadesInaptas, $this->pesoApto,
            $pautas, $this->arquivadaEm, $this->arquivadaMotivo, $this->arquivadaPorNome, $this->entidade, $this->cnpj, $this->cenario,
            rotuloUnidade: $this->rotuloUnidade, rotuloBloco: $this->rotuloBloco,
            editalNome: $this->editalNome, editalTamanho: $this->editalTamanho, editalSha256: $this->editalSha256,
            editalChave: $this->editalChave, editalAlteravel: $this->editalAlteravel, linkSalaVirtual: $this->linkSalaVirtual,
        );
    }

    public function temEdital(): bool
    {
        return null !== $this->editalChave;
    }

    public function publicada(): bool
    {
        return EstadoDaAssembleia::Publicada === $this->estado;
    }

    /** Andamento exibido, derivado das pautas, sem alterar o estado persistido da assembleia. */
    public function situacao(): string
    {
        if (!$this->publicada()) {
            return 'rascunho';
        }
        $encerradas = 0;
        $naOrdemDoDia = 0;
        foreach ($this->pautas as $pauta) {
            if ($pauta->retirada()) {
                continue;
            }
            ++$naOrdemDoDia;
            if ($pauta->aberta()) {
                return 'aberta';
            }
            $encerradas += $pauta->encerrada() ? 1 : 0;
        }

        return $encerradas > 0 && $encerradas === $naOrdemDoDia ? 'encerrada' : 'publicada';
    }

    /**
     * Etapa atual das 6 do desenho (Criar, Lista de aptos, Publicar, Votação, Apuração, Relatório), de 0 a 6: as de
     * número menor estão concluídas, a de mesmo número é a atual e 6 quer dizer tudo concluído. Rascunho sem lista de
     * aptos está em "Lista de aptos" (1); com lista, em "Publicar" (2); publicada ou aberta, em "Votação" (3). O sistema
     * apura ao encerrar, então encerrada conclui até o Relatório, como a votação apurada do desenho.
     */
    public function etapaAtual(): int
    {
        return match ($this->situacao()) {
            'rascunho' => $this->unidadesAptas + $this->unidadesInaptas > 0 ? 2 : 1,
            'encerrada' => 6,
            default => 3,
        };
    }

    /** CNPJ com a máscara ("00.000.000/0000-00"); vazio sem CNPJ. */
    public function cnpjFormatado(): string
    {
        return null === $this->cnpj ? '' : CnpjDaEntidade::formatar($this->cnpj);
    }

    public function arquivada(): bool
    {
        return null !== $this->arquivadaEm;
    }

    /** Só rascunho nunca publicado pode ser excluído. */
    public function podeExcluir(): bool
    {
        return !$this->publicada() && null === $this->publicadaEm;
    }

    /**
     * Pode ser apagada para sempre (a mesma regra de Assembleia::exigirApagavel()): sem pauta aberta (nem suspensa) e sem
     * pauta programada para abrir (publicada e não arquivada). Rascunho e simulação têm o próprio "Excluir"/"Apagar".
     */
    public function podeApagar(): bool
    {
        if (!$this->publicada()) {
            return false;
        }
        foreach ($this->pautas as $pauta) {
            if ($pauta->aberta() || (!$pauta->retirada() && !$pauta->encerrada() && $pauta->programada() && null === $this->arquivadaEm)) {
                return false;
            }
        }

        return true;
    }

    /** Na seleção em lote: rascunho nunca publicado ou simulação (a simulação se apaga, nunca se arquiva). */
    public function podeExcluirEmLote(): bool
    {
        return $this->simulacao || $this->podeExcluir();
    }

    public function porFracao(): bool
    {
        return PesoDoVoto::Fracao === $this->peso;
    }

    /** Pautas já abertas ou encerradas (o que Acompanhar e Resultados mostram). */
    public function temPautaQueSaiuDaEspera(): bool
    {
        foreach ($this->pautas as $pauta) {
            if (!$pauta->retirada() && ($pauta->aberta() || $pauta->encerrada())) {
                return true;
            }
        }

        return false;
    }

    /** @return list<PautaNoPainel> as encerradas COM resultado (a cancelada fica de fora: não é apurada) */
    public function pautasEncerradas(): array
    {
        return array_values(array_filter($this->pautas, static fn (PautaNoPainel $p): bool => $p->encerrada() && !$p->cancelada()));
    }

    /** @return list<PautaNoPainel> as canceladas (cédulas lacradas, sem resultado) */
    public function pautasCanceladas(): array
    {
        return array_values(array_filter($this->pautas, static fn (PautaNoPainel $p): bool => $p->cancelada()));
    }

    /** @return list<PautaNoPainel> as retiradas da ordem do dia */
    public function pautasRetiradas(): array
    {
        return array_values(array_filter($this->pautas, static fn (PautaNoPainel $p): bool => $p->retirada()));
    }
}
