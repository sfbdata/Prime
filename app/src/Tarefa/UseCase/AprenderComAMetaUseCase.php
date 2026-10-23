<?php

declare(strict_types=1);

namespace App\Tarefa\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Pasta\Service\DiasUteisDoEscritorio;
use App\Pasta\Service\MencoesDoRegistro;
use App\Tarefa\DTO\DespacharNaMetaInput;
use App\Tarefa\Entity\AprendizadoDaPendencia;
use App\Tarefa\Entity\AprendizadoDoDespacho;
use App\Tarefa\Enum\EtapaDaPasta;
use App\Tarefa\Repository\AprendizadoDaPendenciaRepository;
use App\Tarefa\Repository\AprendizadoDoDespachoRepository;
use App\Tarefa\Service\PreferenciaDoAprendizado;

/**
 * Grava o aprendizado da meta (desenho 04B de 22/10, L145 e L173; spec `minhas-metas-esteira-2210.md`, 1.1 itens 1 e
 * 2, C2, D4 e Fase 4). Chamado depois que o despacho e a passagem já foram gravados.
 *
 * - Despacho: só o "Despachar" com texto (o envio para revisão não ensina nada, como no protótipo). Guarda a etapa da
 *   meta, a natureza, o texto (as @menções viram "@Nome") e os dias úteis do prazo pela regra do escritório (0 sem
 *   prazo).
 * - Pendências: cada linha não vazia, pela etapa destino. Sem etapa destino (a pasta encerrou), não há o que guardar.
 *
 * Com a chave do Meu Perfil desligada (D4), nada é gravado. Guarda só os {@see LIMITE_DE_DESPACHOS} despachos e as
 * {@see LIMITE_DE_PENDENCIAS} linhas mais recentes da pessoa no escritório, como o protótipo (400 e 300): o resto sai.
 */
final class AprenderComAMetaUseCase
{
    public const LIMITE_DE_DESPACHOS = 400;
    public const LIMITE_DE_PENDENCIAS = 300;
    /** Teto da contagem dos dias úteis do prazo: dois anos. */
    private const TETO_DE_DIAS = 730;

    public function __construct(
        private readonly AprendizadoDoDespachoRepository $despachos,
        private readonly AprendizadoDaPendenciaRepository $pendencias,
        private readonly PreferenciaDoAprendizado $preferencia,
        private readonly DiasUteisDoEscritorio $diasUteis,
    ) {
    }

    /** @return bool se gravou */
    public function doDespacho(Tarefa $meta, User $autor, Tenant $escritorio, DespacharNaMetaInput $input, \DateTimeImmutable $hoje): bool
    {
        $texto = trim((string) preg_replace_callback(MencoesDoRegistro::PADRAO, static fn (array $m): string => '@' . $m[1], $input->texto));
        if ($input->acao !== '' || $texto === '' || $meta->getTenant()?->getId() !== $escritorio->getId() || !$this->preferencia->ligado($escritorio, $autor)) {
            return false;
        }
        $this->despachos->salvar(new AprendizadoDoDespacho(
            $escritorio,
            $autor,
            $meta->getEtapa(),
            $input->naturezaEscolhida(),
            $texto,
            $this->uteisAte($hoje, $input->prazoComoData(), $escritorio->getEstado()),
        ));
        $this->despachos->confirmar();
        foreach ($this->despachos->doUsuario($escritorio, $autor, null, self::LIMITE_DE_DESPACHOS) as $antigo) {
            $this->despachos->remover($antigo);
        }
        $this->despachos->confirmar();

        return true;
    }

    /** @return int quantas linhas gravou */
    public function dasPendencias(User $autor, Tenant $escritorio, ?EtapaDaPasta $destino, string $pendencias): int
    {
        $linhas = array_values(array_filter(array_map('trim', preg_split('/\R/u', $pendencias) ?: []), static fn (string $l): bool => $l !== ''));
        if ($destino === null || $linhas === [] || !$this->preferencia->ligado($escritorio, $autor)) {
            return 0;
        }
        foreach ($linhas as $linha) {
            $this->pendencias->salvar(new AprendizadoDaPendencia($escritorio, $autor, $destino, $linha));
        }
        $this->pendencias->confirmar();
        foreach ($this->pendencias->doUsuario($escritorio, $autor, null, self::LIMITE_DE_PENDENCIAS) as $antiga) {
            $this->pendencias->remover($antiga);
        }
        $this->pendencias->confirmar();

        return count($linhas);
    }

    /** Os dias úteis de hoje (exclusive) até o prazo (inclusive), pela regra do escritório; 0 sem prazo. */
    private function uteisAte(\DateTimeImmutable $hoje, ?\DateTimeImmutable $prazo, ?string $uf): int
    {
        $dia = $hoje->setTime(0, 0);
        $uteis = 0;
        for ($i = 0; $prazo !== null && $dia < $prazo->setTime(0, 0) && $i < self::TETO_DE_DIAS; ++$i) {
            $dia = $dia->modify('+1 day');
            if ($this->diasUteis->ehUtil($dia, $uf)) {
                ++$uteis;
            }
        }

        return $uteis;
    }
}
