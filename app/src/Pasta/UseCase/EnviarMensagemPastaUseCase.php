<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Entity\Permission\AccessRequest;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaMensagem;
use App\Entity\Tenant\Tenant;
use App\Repository\NotificacaoRepository;
use App\Repository\UserTenantRepository;
use App\Service\NotificacaoService;
use App\Service\PermissionChecker;
use App\Shared\Service\SanitizadorTextoRico;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Grava um registro (ou uma resposta) no Registro da pasta.
 *
 * Quando é RESPOSTA, o autor do comentário respondido (a raiz da conversa) recebe uma
 * notificação no sino — desenho `bluejus-central.js` `notificarResposta`, tipo "direcionada":
 * título "<Primeiro nome> respondeu seu comentário", texto com a resposta (140) e o comentário
 * original (80). Regras de quem recebe:
 *  - nunca quem respondeu (responder o próprio comentário não notifica);
 *  - só quem ainda tem vínculo ATIVO neste escritório E acesso de leitura a ESTA pasta
 *    (a mesma checagem da `pasta_show`) — sem acesso, o link levaria a uma tela negada;
 *  - a notificação é do escritório da pasta (Notificacao é TenantAware);
 *  - sem duplicata: se o destinatário já tem uma notificação NÃO LIDA idêntica (mesmo tipo,
 *    mesmo título, mesmo link — isto é, a mesma pessoa respondendo o mesmo comentário), não
 *    cria outra. Lida a anterior, a próxima resposta volta a notificar.
 */
final class EnviarMensagemPastaUseCase
{
    /** Onde o Registro mora na pasta_show — vai no texto da notificação ("em Dados da Pasta N"). */
    private const ABA_DO_REGISTRO = 'Dados';

    private const LIMITE_RESPOSTA = 140;
    private const LIMITE_ORIGINAL = 80;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SanitizadorTextoRico $sanitizador,
        private readonly NotificacaoService $notificacaoService,
        private readonly NotificacaoRepository $notificacaoRepository,
        private readonly UserTenantRepository $userTenantRepository,
        private readonly PermissionChecker $permissionChecker,
        private readonly UrlGeneratorInterface $urlGenerator,
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

        if ($raiz !== null) {
            $this->notificarAutorDoComentario($raiz, $mensagem, $pasta, $autor, $tenant);
        }

        // Uma transação só: a resposta e a notificação entram (ou não) juntas.
        $this->em->flush();

        return $mensagem;
    }

    /**
     * O destinatário é o autor da RAIZ: é a ela que a resposta fica pendurada na tela
     * ("Resposta a <nome>"), e é o id dela que o botão Responder manda.
     */
    private function notificarAutorDoComentario(PastaMensagem $raiz, PastaMensagem $resposta, Pasta $pasta, User $quemResponde, Tenant $tenant): void
    {
        $destinatario = $raiz->getAutor();
        if ($destinatario === null || self::mesmoUsuario($destinatario, $quemResponde)) {
            return;
        }

        $pastaId = $pasta->getId();
        if ($pastaId === null || $raiz->getId() === null) {
            return;
        }

        if (!$this->userTenantRepository->existeVinculoAtivo($destinatario, $tenant)
            || !$this->permissionChecker->canAccessResource($destinatario, $tenant, AccessRequest::RESOURCE_PASTA, $pastaId, AccessRequest::ACTION_VIEW)) {
            return;
        }

        $titulo = sprintf('%s respondeu seu comentário', self::primeiroNome($quemResponde));
        // A âncora é o cartão da raiz (`id="pasta-msg-N"` em _dados_anotacoes): a resposta
        // aparece logo abaixo dela, junto das outras da mesma conversa.
        $url = $this->urlGenerator->generate('pasta_show', ['id' => $pastaId]) . '#pasta-msg-' . $raiz->getId();

        $jaExiste = $this->notificacaoRepository->findOneBy([
            'usuario' => $destinatario,
            'tenant'  => $tenant,
            'tipo'    => Notificacao::TIPO_PASTA_RESPOSTA_REGISTRO,
            'titulo'  => $titulo,
            'url'     => $url,
            'lida'    => false,
        ]);
        if ($jaExiste !== null) {
            return;
        }

        $texto = sprintf(
            '"%s" · em %s da Pasta %s · %s. Seu comentário: "%s"',
            mb_substr($this->textoPlano($resposta->getConteudo()), 0, self::LIMITE_RESPOSTA),
            self::ABA_DO_REGISTRO,
            ($nup = $pasta->getNup()) !== null && $nup !== '' ? $nup : '#' . $pastaId,
            $resposta->getCriadaEm()->format('d/m/Y H:i'),
            mb_substr($this->textoPlano($raiz->getConteudo()), 0, self::LIMITE_ORIGINAL),
        );

        $this->notificacaoService
            ->criar($destinatario, $tenant, Notificacao::TIPO_PASTA_RESPOSTA_REGISTRO, $titulo, $texto)
            ->setUrl($url);
    }

    /**
     * O conteúdo é HTML do editor rico; o sino mostra texto. Fim de bloco e <br> viram espaço
     * (senão "linha um</p><p>linha dois" grudaria), entidades são decodificadas e os brancos
     * colapsados.
     */
    private function textoPlano(?string $html): string
    {
        $html  = preg_replace('#<br\s*/?>|</(p|li|h[1-6]|blockquote|pre)>#i', ' ', (string) $html) ?? '';
        $texto = html_entity_decode(strip_tags($html), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/[\s\p{Z}]+/u', ' ', $texto) ?? '');
    }

    private static function primeiroNome(User $usuario): string
    {
        $nome = trim((string) $usuario->getFullName());
        if ($nome === '') {
            return 'Alguém';
        }

        return explode(' ', $nome)[0];
    }

    private static function mesmoUsuario(User $a, User $b): bool
    {
        return $a === $b || ($a->getId() !== null && $a->getId() === $b->getId());
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
