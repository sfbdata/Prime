<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaMensagem;
use App\Entity\Tenant\Tenant;
use App\Shared\Service\SanitizadorTextoRico;
use Doctrine\ORM\EntityManagerInterface;

final class EnviarMensagemPastaUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SanitizadorTextoRico $sanitizador,
    ) {}

    /**
     * @param PastaMensagem|null $respostaA o registro que esta mensagem responde. Tem de ser da
     *        MESMA pasta e do MESMO escritório — o id veio do navegador, então a posse é conferida
     *        aqui. Responder a uma resposta liga à RAIZ dela: a conversa tem um nível só.
     */
    public function executar(Pasta $pasta, User $autor, string $conteudo, Tenant $tenant, ?PastaMensagem $respostaA = null): PastaMensagem
    {
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

        $mensagem = new PastaMensagem();
        $mensagem->setPasta($pasta);
        $mensagem->setAutor($autor);
        $mensagem->setTenant($tenant);
        $mensagem->setConteudo($conteudo);
        $mensagem->setRespostaA($raiz);

        $this->em->persist($mensagem);
        $this->em->flush();

        return $mensagem;
    }

    /**
     * Mesma instância ou mesmo id (proxies do ORM). Dois objetos sem id NÃO são o mesmo:
     * sem id não há como provar a posse, e a dúvida recusa.
     */
    private static function mesmo(Tenant|Pasta|null $a, Tenant|Pasta $b): bool
    {
        if ($a === null) {
            return false;
        }

        return $a === $b || ($a->getId() !== null && $a->getId() === $b->getId());
    }
}
