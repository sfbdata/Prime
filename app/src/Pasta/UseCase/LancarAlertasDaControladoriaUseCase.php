<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\AlertaDaPasta;
use App\Pasta\DTO\ResultadoDaRotinaDaControladoria;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\Repository\PastaMensagemRepository;
use App\Pasta\Repository\PastaRepository;
use App\Pasta\Service\AlertasDaPasta;
use App\Pasta\Service\RegistroDoSistemaNaPasta;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Rotina de segunda-feira da Controladoria (spec docs/specs/central-documentos-controladoria.md, "Rotina de
 * segunda-feira"). Roda a mesma análise do painel ({@see AlertasDaPasta}) em todas as pastas ativas do escritório e
 * lança no Registro de cada pasta, como cartão do sistema, SÓ o alerta NOVO.
 *
 * Sem migration: o estado "quais alertas já foram lançados e seguem valendo" mora no último cartão `controladoria`
 * da pasta, num `<span hidden data-controladoria="metas,parada">`. A cada rodada:
 *  - alerta que vale agora e não está no estado = NOVO: entra num cartão novo (o estado do cartão novo é o conjunto
 *    que vale agora);
 *  - alerta que está no estado e segue valendo: não repete;
 *  - alerta que sumiu: sai do estado do último cartão (sem cartão novo), então, se voltar, é novo de novo.
 * Alerta de nível BAIXO não entra no Registro (nem no estado): só médio e alto. Sem esse corte, um alerta baixo que vale
 * para quase toda pasta (ex.: cadastro do réu incompleto) lançaria um cartão em cada pasta na primeira segunda.
 * O cartão é escrito em nome do responsável da pasta (ou de quem a criou); pasta sem os dois é pulada.
 */
final class LancarAlertasDaControladoriaUseCase
{
    public const TIPO = 'controladoria';

    private const MARCA = '/<span hidden data-controladoria="([a-z,]*)"><\/span>/';

    public function __construct(
        private readonly PastaRepository $pastas,
        private readonly PastaMensagemRepository $mensagens,
        private readonly AlertasDaPasta $alertas,
        private readonly RegistroDoSistemaNaPasta $registro,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function executar(Tenant $tenant, bool $simular = false): ResultadoDaRotinaDaControladoria
    {
        $hoje     = \DateTimeImmutable::createFromInterface($this->relogio->now());
        $pastas   = $this->pastas->ativasParaAnaliseSemProcesso($tenant);
        $ultimos  = $this->mensagens->ultimoDoSistemaPorPasta($tenant, self::TIPO, array_map(static fn (Pasta $p): int => (int) $p->getId(), $pastas));
        $comNovo  = 0;
        $novos    = 0;
        $porTipo  = [];

        foreach ($pastas as $pasta) {
            $achados = array_values(array_filter(
                $this->alertas->montar($pasta, $tenant, $hoje),
                static fn (AlertaDaPasta $a): bool => $a->nivel !== AlertaDaPasta::BAIXA,
            ));
            $novosDaPasta = $this->processar($pasta, $achados, $ultimos[(int) $pasta->getId()] ?? null, $simular);
            foreach ($novosDaPasta as $a) {
                $porTipo[$a->chave] = ($porTipo[$a->chave] ?? 0) + 1;
            }
            if ($novosDaPasta !== []) {
                ++$comNovo;
                $novos += count($novosDaPasta);
            }
        }
        if (!$simular) {
            $this->em->flush();
        }

        ksort($porTipo);

        return new ResultadoDaRotinaDaControladoria(count($pastas), $comNovo, $novos, $porTipo);
    }

    /**
     * @param list<AlertaDaPasta> $achados
     *
     * @return list<AlertaDaPasta> os alertas novos da pasta
     */
    private function processar(Pasta $pasta, array $achados, ?PastaMensagem $ultimo, bool $simular): array
    {
        $vigentes = array_values(array_unique(array_map(static fn (AlertaDaPasta $a): string => $a->chave, $achados)));
        $lancados = $ultimo !== null ? $this->chavesDoCartao($ultimo) : [];
        $novos    = array_values(array_filter($achados, static fn (AlertaDaPasta $a): bool => !in_array($a->chave, $lancados, true)));

        if ($novos === []) {
            if (!$simular && $ultimo !== null && $lancados !== array_values(array_intersect($lancados, $vigentes))) {
                $ultimo->setConteudo($this->comEstado($ultimo->getConteudo(), $vigentes));
            }

            return [];
        }
        $autor = $pasta->getResponsavel() ?? $pasta->getCriadoPor();
        if ($autor === null) {
            return [];
        }
        if (!$simular) {
            $cartao = $this->registro->registrar($pasta, $autor, self::TIPO, $this->texto($novos));
            $cartao->setConteudo($this->comEstado($cartao->getConteudo(), $vigentes));
        }

        return $novos;
    }

    /** @param list<AlertaDaPasta> $novos */
    private function texto(array $novos): string
    {
        return implode(' ', array_map(
            static fn (AlertaDaPasta $a): string => 'Alerta ' . $a->nivel . ': ' . $a->titulo . '. ' . $a->detalhe,
            $novos,
        ));
    }

    /** @return list<string> */
    private function chavesDoCartao(PastaMensagem $cartao): array
    {
        if (preg_match(self::MARCA, $cartao->getConteudo(), $m) !== 1 || $m[1] === '') {
            return [];
        }

        return explode(',', $m[1]);
    }

    /** @param list<string> $vigentes */
    private function comEstado(string $conteudo, array $vigentes): string
    {
        $marca = '<span hidden data-controladoria="' . implode(',', $vigentes) . '"></span>';
        if (preg_match(self::MARCA, $conteudo) === 1) {
            return (string) preg_replace(self::MARCA, $marca, $conteudo);
        }

        return $conteudo . $marca;
    }
}
