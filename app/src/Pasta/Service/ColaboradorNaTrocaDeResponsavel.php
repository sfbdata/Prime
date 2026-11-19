<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\CompartilharPastaInput;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PermissoesDoCompartilhamento;
use App\Pasta\Exception\CompartilhamentoRecusadoException;
use App\Pasta\Repository\PastaCompartilhamentoRepository;
use App\Pasta\UseCase\CompartilharPastaComAEquipeUseCase;
use Psr\Clock\ClockInterface;

/**
 * "Manter Y como colaborador" na troca de responsável (spec acesso-restrito-e-compartilhar, regra 5 e L8; desenho 03,
 * `rcfSim`): o responsável anterior ganha um compartilhamento interno da pasta pelo mesmo caminho do "Compartilhar
 * com a equipe" (fatia 5a), com as permissões do 1 clique do desenho (movimentar e enviar documentos, sem baixar),
 * sem validade e com a mensagem do desenho. Sem isso, se ele tiver acesso restrito, deixa de ver a pasta e as metas.
 *
 * Chamado depois de a pasta já ter o novo responsável (o compartilhar recusa o responsável como destino). A troca não
 * falha por causa disto: quando o compartilhar recusa (o anterior não tem mais vínculo ativo no escritório, ou quem
 * troca não pode compartilhar), devolve falso e o aviso diz que ele foi desvinculado. Se ele já colabora na pasta, o
 * compartilhamento que existe fica como está e conta como mantido. Quem passa a própria pasta adiante não é restrito
 * (a troca recusa o restrito antes): segue vendo a pasta sem compartilhamento, e conta como mantido.
 */
final class ColaboradorNaTrocaDeResponsavel
{
    public const MENSAGEM = 'Mantido como colaborador na troca de responsável';

    public function __construct(
        private readonly CompartilharPastaComAEquipeUseCase $compartilhar,
        private readonly PastaCompartilhamentoRepository $compartilhamentos,
        private readonly ClockInterface $relogio,
    ) {
    }

    /** Devolve se o anterior segue colaborando na pasta (já colaborava ou passou a colaborar agora). */
    public function manter(Pasta $pasta, User $anterior, User $autor, Tenant $tenant): bool
    {
        if ($anterior->getId() === $autor->getId()) {
            return true;
        }
        $permissoes = PermissoesDoCompartilhamento::umClique();
        $input = new CompartilharPastaInput(
            $anterior->getId(),
            $permissoes->movimentar,
            $permissoes->enviarDocumentos,
            $permissoes->baixarDocumentos,
            null,
            self::MENSAGEM,
        );

        try {
            $this->compartilhar->executar((int) $pasta->getId(), $input, $autor, $tenant);
        } catch (CompartilhamentoRecusadoException) {
            // Já colabora? Pergunta ao banco, não ao texto da recusa.
            return $this->compartilhamentos->ocupandoAVaga($pasta, $anterior)?->estaAtivo($this->relogio->now()) === true;
        }

        return true;
    }
}
