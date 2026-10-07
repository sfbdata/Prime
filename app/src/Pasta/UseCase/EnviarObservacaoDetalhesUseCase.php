<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaObservacaoDetalhes;
use App\Entity\Tenant\Tenant;
use App\Pasta\Service\ResolucaoDeMencoesDoRegistro;
use App\Shared\Service\SanitizadorTextoRico;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Grava uma observação (ou uma resposta) no Relatório inicial de Atendimento (aba Detalhes).
 *
 * RESPONDER (PAS-039; desenho 1.2.3, o mesmo `regsLinha` do Registro da aba Dados): a resposta
 * fica pendurada na RAIZ — responder a uma resposta liga à raiz dela, um nível só. O registro
 * respondido veio do navegador, então a posse é conferida aqui: ele e a raiz têm de ser desta
 * pasta e deste escritório, senão nada é gravado. O autor da raiz recebe "<Primeiro nome>
 * respondeu seu comentário" pela {@see ResolucaoDeMencoesDoRegistro} (as regras e a cota do
 * Registro); o aviso e o registro entram na mesma transação.
 */
final class EnviarObservacaoDetalhesUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SanitizadorTextoRico $sanitizador,
        private readonly ResolucaoDeMencoesDoRegistro $resolucaoDeMencoes,
    ) {}

    /**
     * @param PastaObservacaoDetalhes|null $respostaA o registro que este responde (mesma pasta,
     *        mesmo escritório); a resposta liga à RAIZ dele
     */
    public function executar(Pasta $pasta, User $autor, string $conteudo, Tenant $tenant, ?PastaObservacaoDetalhes $respostaA = null): PastaObservacaoDetalhes
    {
        // A pasta tem de ser DESTE escritório: o registro nasce com o tenant da sessão. O
        // controller já barra antes; aqui é a mesma prova, sem depender de quem chama.
        if (!self::mesmo($pasta->getTenant(), $tenant)) {
            throw new \InvalidArgumentException('Só é possível registrar numa pasta deste escritório.');
        }

        // Vem do editor rico (HTML): limpo ANTES de persistir. Texto puro atravessa intacto.
        $conteudo = $this->sanitizador->limpar(trim($conteudo)) ?? '';

        // `estaVazio` porque o editor entrega `<p><br></p>` quando nada foi digitado; o limite
        // conta o texto visível, não a marcação.
        if ($this->sanitizador->estaVazio($conteudo) || $this->sanitizador->comprimentoDoTexto($conteudo) > 5000) {
            throw new \InvalidArgumentException('Conteúdo inválido: deve ter entre 1 e 5000 caracteres.');
        }

        $raiz = null;
        if ($respostaA !== null) {
            $raiz = $respostaA->getRespostaA() ?? $respostaA;

            // A raiz também é conferida: barato, e não depende de o vínculo antigo estar são.
            foreach ([$respostaA, $raiz] as $alvo) {
                if (!self::mesmo($alvo->getTenant(), $tenant) || !self::mesmo($alvo->getPasta(), $pasta)) {
                    throw new \InvalidArgumentException('Só é possível responder a um registro desta pasta.');
                }
            }
        }

        $obs = new PastaObservacaoDetalhes();
        $obs->setPasta($pasta);
        $obs->setAutor($autor);
        $obs->setTenant($tenant);
        $obs->setConteudo($conteudo);
        $obs->setRespostaA($raiz);

        if ($raiz === null) {
            $this->em->persist($obs);
            $this->em->flush();

            return $obs;
        }

        // O link do aviso leva o id da RESPOSTA, que só existe depois do flush — por isso o flush
        // acontece dentro da transação, antes do aviso.
        return $this->em->wrapInTransaction(function () use ($obs, $raiz, $pasta, $autor, $tenant): PastaObservacaoDetalhes {
            $this->em->persist($obs);
            $this->em->flush();

            $this->resolucaoDeMencoes->notificarRespostaNaObservacao($raiz, $obs, $pasta, $autor, $tenant);

            return $obs;
        });
    }

    /**
     * Mesma instância ou mesmo id (proxies do ORM). Dois objetos sem id NÃO são o mesmo: sem id
     * não há como provar a posse, e a dúvida recusa.
     */
    private static function mesmo(Tenant|Pasta|null $a, Tenant|Pasta $b): bool
    {
        if ($a === null) {
            return false;
        }

        return $a === $b || ($a->getId() !== null && $a->getId() === $b->getId());
    }
}
