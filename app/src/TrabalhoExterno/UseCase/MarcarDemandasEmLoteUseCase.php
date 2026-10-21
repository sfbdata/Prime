<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\Entity\DemandaExterna;
use App\TrabalhoExterno\Entity\LeituraDemanda;
use App\TrabalhoExterno\Entity\MarcacaoDoUsuario;
use App\TrabalhoExterno\Enum\OperacaoDeMarcacao;
use App\TrabalhoExterno\Enum\TipoItemMarcado;
use App\TrabalhoExterno\Exception\AcaoNaoPermitida;
use App\TrabalhoExterno\Repository\LeituraDemandaRepository;
use App\TrabalhoExterno\Repository\MarcacaoDoUsuarioRepository;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\LocalizadorDeDemanda;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Barra de lote, estrela, importância e menu da linha (`lote()` do desenho, spec VS 18.10.26 §3.1).
 * Quem: qualquer perfil que vê a demanda. Só vale para ele: a demanda não muda para os outros.
 * Código que o usuário não vê é ignorado em silêncio (não revela que existe); a resposta conta
 * só os aplicados. Na prévia do Master é escrita e fica negada.
 */
final class MarcarDemandasEmLoteUseCase
{
    public function __construct(
        private readonly LocalizadorDeDemanda $localizador,
        private readonly MarcacaoDoUsuarioRepository $marcacoes,
        private readonly LeituraDemandaRepository $leituras,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param list<string> $codigos
     *
     * @return array{aplicados: int, texto: string}
     */
    public function executar(Contexto $ctx, array $codigos, OperacaoDeMarcacao $op): array
    {
        $this->exigirForaDaPrevia($ctx);
        $demandas = $this->visiveisEntre($ctx, $codigos);
        $existentes = $this->marcacoes->doUsuario($ctx->usuario, TipoItemMarcado::Demanda, array_keys($demandas));
        $agora = new \DateTimeImmutable();

        foreach ($demandas as $id => $d) {
            $marcacao = $existentes[$id] ?? new MarcacaoDoUsuario($ctx->usuario, TipoItemMarcado::Demanda, $id);
            $marcacao->aplicar($op, $agora);
            $this->marcacoes->salvar($marcacao);
            if ($op === OperacaoDeMarcacao::Lida) {
                $this->lerAteAUltima($ctx, $d);
            }
        }
        $this->em->flush();

        return ['aplicados' => count($demandas), 'texto' => count($demandas) . ' demanda(s)' . $op->complementoDaDemanda()];
    }

    private function exigirForaDaPrevia(Contexto $ctx): void
    {
        if ($ctx->previa) {
            throw new AcaoNaoPermitida('Na pré-visualização não é possível alterar nada.');
        }
    }

    /**
     * @param list<string> $codigos
     *
     * @return array<int, DemandaExterna> id → demanda, só as que o usuário enxerga
     */
    private function visiveisEntre(Contexto $ctx, array $codigos): array
    {
        $pedidos = array_flip($codigos);
        $demandas = [];
        foreach ($this->localizador->visiveis($ctx) as [$d]) {
            if (isset($pedidos[$d->getCodigo()])) {
                $demandas[(int) $d->getId()] = $d;
            }
        }

        return $demandas;
    }

    private function lerAteAUltima(Contexto $ctx, DemandaExterna $d): void
    {
        $mensagens = $d->getMensagens();
        $leitura = $this->leituras->daDemanda($d, $ctx->usuario) ?? new LeituraDemanda($d, $ctx->usuario);
        $leitura->marcarAte($mensagens === [] ? 0 : (int) end($mensagens)->getId());
        $this->leituras->salvar($leitura);
    }
}
