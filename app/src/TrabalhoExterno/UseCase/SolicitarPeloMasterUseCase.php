<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\Repository\UserTenantRepository;
use App\TrabalhoExterno\DTO\DadosDaSolicitacao;
use App\TrabalhoExterno\Entity\DemandaExterna;
use App\TrabalhoExterno\Enum\Perfil;
use App\TrabalhoExterno\Exception\AcaoNaoPermitida;
use App\TrabalhoExterno\Service\Contexto;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * "+ Nova solicitação" do Master na pré-visualização de Solicitante (spec §9.1, decisão do dono de
 * 16/10/2026). O Master nunca é Solicitante (o papel vale em qualquer escritório), mas pode pedir um
 * serviço em nome de um escritório AO QUAL ELE ESTÁ VINCULADO.
 *
 * História: o Master escolhe um dos escritórios da própria conta; conferimos que o vínculo existe e
 * está ativo (escritório de terceiro é recusado); a solicitação é aberta pelo MESMO caminho do
 * Solicitante (validação, aba bloqueada, central suspensa), com aquele escritório como dono e o Master
 * como autor, e já sai ENVIADA: um rascunho do Master ficaria órfão, porque o Master não vê rascunhos.
 */
final class SolicitarPeloMasterUseCase
{
    public function __construct(
        private readonly UserTenantRepository $vinculos,
        private readonly SalvarSolicitacaoUseCase $salvar,
    ) {
    }

    /** @param list<UploadedFile> $arquivos */
    public function executar(Contexto $master, int $escritorioId, DadosDaSolicitacao $dados, bool $enviar, array $arquivos = []): DemandaExterna
    {
        if ($master->perfil !== Perfil::Master || $master->previa) {
            throw new AcaoNaoPermitida('Só o Master abre solicitação em nome de um dos seus escritórios.');
        }
        if (!$enviar) {
            throw new AcaoNaoPermitida('Pelo Master a solicitação é enviada direto; rascunho fica com o escritório.');
        }

        $escritorio = null;
        foreach ($this->vinculos->findActiveByUser($master->usuario) as $vinculo) {
            if ($vinculo->getTenant()?->getId() === $escritorioId) {
                $escritorio = $vinculo->getTenant();
            }
        }
        if ($escritorio === null) {
            throw new AcaoNaoPermitida('Escolha um dos escritórios da sua conta.');
        }

        $comoEscritorio = new Contexto($master->usuario, $escritorio, Perfil::Solicitante, $master->configuracao);

        return $this->salvar->executar($comoEscritorio, $dados, true, null, $arquivos);
    }
}
