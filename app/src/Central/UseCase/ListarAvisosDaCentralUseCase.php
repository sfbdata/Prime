<?php

declare(strict_types=1);

namespace App\Central\UseCase;

use App\Central\DTO\AvisoOutput;
use App\Central\Exception\SemAcessoACentralException;
use App\Central\Service\AcessoACentral;
use App\Central\Service\AcessoAosAvisos;
use App\Central\Service\AvisosDaCentral;
use App\Central\Service\TiposDeAvisoDaCentral;
use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Entity\Tenant\Tenant;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Os avisos do sino na Caixa da Central (F1, `docs/specs/central-comunicacao-f1-avisos.md`): os 100 mais recentes da
 * pessoa neste escritório, sem gestão e sem os da própria Central, rechecando o acesso à pasta na leitura. O link é o
 * mesmo do sino: a URL gravada, ou a meta.
 */
final class ListarAvisosDaCentralUseCase
{
    public const LIMITE = 100;

    public function __construct(
        private readonly AcessoACentral $acessoACentral,
        private readonly AvisosDaCentral $avisos,
        private readonly AcessoAosAvisos $acesso,
        private readonly TiposDeAvisoDaCentral $tipos,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @return list<AvisoOutput>
     *
     * @throws SemAcessoACentralException
     */
    public function executar(User $eu, ?Tenant $tenant): array
    {
        $tenant = $this->acessoACentral->exigirVinculoAtivo($eu, $tenant);

        $saida = [];
        $memoria = [];
        foreach ($this->avisos->recentes($eu, $tenant, self::LIMITE) as $aviso) {
            if (!$this->acesso->podeVer($aviso, $eu, $tenant, $memoria)) {
                continue;
            }
            $saida[] = $this->paraSaida($aviso);
        }

        return $saida;
    }

    private function paraSaida(Notificacao $aviso): AvisoOutput
    {
        $tipo = $this->tipos->para($aviso->getTipo());
        $url = $aviso->getUrl();
        if (($url === null || $url === '') && $aviso->getTarefa() !== null) {
            $url = $this->urls->generate('tarefa_show', ['id' => $aviso->getTarefa()->getId()]);
        }

        return new AvisoOutput(
            id: (int) $aviso->getId(),
            tipo: $aviso->getTipo(),
            grupo: $tipo['grupo'],
            rotulo: $tipo['rotulo'],
            icone: $tipo['icone'],
            cor: $tipo['cor'],
            nivel: $tipo['nivel'],
            titulo: $aviso->getTitulo(),
            texto: $aviso->getMensagem(),
            url: $url !== '' ? $url : null,
            lido: $aviso->isLida(),
            criadoEm: $aviso->getCriadaEm()->format(\DATE_ATOM),
        );
    }
}
