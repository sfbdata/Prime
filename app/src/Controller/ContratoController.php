<?php

namespace App\Controller;

use App\Entity\Cliente\Cliente;
use App\Entity\Contrato\Contrato;
use App\Repository\ClienteRepository;
use App\Repository\ContratoRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * ContratoController - Gerencia contratos.
 *
 * Estrutura de rotas REST:
 * - GET  /contratos                                       → Lista todos os contratos
 * - GET  /contratos/{id}                                  → Exibe detalhes do contrato
 * - POST /contratos/{id}/associar-cliente                 → Associa cliente ao contrato
 * - POST /contratos/{id}/desassociar-cliente/{clienteId}  → Desassocia cliente
 * - POST /contratos/{id}/status                           → Altera status do contrato
 *
 * Rotas aninhadas por contexto (opcional):
 * - GET  /contratos/{contratoId}/processos → Lista processos do contrato
 */
#[Route('/contratos')]
class ContratoController extends AbstractController
{
    #[Route('/', name: 'contrato_index', methods: ['GET'])]
    public function index(Request $request, ContratoRepository $repo, ClienteRepository $clienteRepo): Response
    {
        $filters = [
            'cliente_id' => $request->query->get('cliente_id', ''),
            'data_inicio_de' => $request->query->get('data_inicio_de', ''),
            'data_inicio_ate' => $request->query->get('data_inicio_ate', ''),
            'valor_min' => $request->query->get('valor_min', ''),
            'valor_max' => $request->query->get('valor_max', ''),
        ];

        $hasFilters = array_filter($filters, fn($v) => $v !== '');

        return $this->render('contrato/index.html.twig', [
            'contratos' => $hasFilters ? $repo->findByFilters($filters) : $repo->findAll(),
            'filters' => $filters,
            'clientes' => $clienteRepo->findAll(),
        ]);
    }

    #[Route('/{id}', name: 'contrato_show', methods: ['GET'])]
    public function show(Contrato $contrato, ClienteRepository $clienteRepository): Response
    {
        $clientesNaoAssociados = array_values(array_filter(
            $clienteRepository->findAll(),
            fn (Cliente $cliente): bool => !$contrato->getClientes()->contains($cliente)
        ));

        return $this->render('contrato/show.html.twig', [
            'contrato' => $contrato,
            'clientesNaoAssociados' => $clientesNaoAssociados,
        ]);
    }

    #[Route('/{id}/associar-cliente', name: 'contrato_associar_cliente', methods: ['POST'])]
    public function associarCliente(Request $request, Contrato $contrato, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('associar_cliente_'.$contrato->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $clienteId = $request->request->getInt('cliente_id');
        if ($clienteId <= 0) {
            return $this->redirectToRoute('contrato_show', ['id' => $contrato->getId()]);
        }

        $cliente = $em->getRepository(Cliente::class)->find($clienteId);
        if (!$cliente instanceof Cliente) {
            return $this->redirectToRoute('contrato_show', ['id' => $contrato->getId()]);
        }

        if (!$contrato->getClientes()->contains($cliente)) {
            $cliente->addContrato($contrato);
            $em->flush();
        }

        return $this->redirectToRoute('contrato_show', ['id' => $contrato->getId()]);
    }

    #[Route('/{id}/desassociar-cliente/{clienteId}', name: 'contrato_desassociar_cliente', methods: ['POST'])]
    public function desassociarCliente(Request $request, Contrato $contrato, int $clienteId, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('desassociar_cliente_'.$contrato->getId().'_'.$clienteId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $cliente = $em->getRepository(Cliente::class)->find($clienteId);
        if (!$cliente instanceof Cliente) {
            return $this->redirectToRoute('contrato_show', ['id' => $contrato->getId()]);
        }

        if ($cliente->getContratos()->contains($contrato)) {
            $cliente->removeContrato($contrato);
            $em->flush();
        }

        return $this->redirectToRoute('contrato_show', ['id' => $contrato->getId()]);
    }

    #[Route('/{id}/status', name: 'contrato_toggle_status', methods: ['POST'])]
    public function toggleStatus(Request $request, Contrato $contrato, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('toggle_status_'.$contrato->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $novoStatus = $request->request->has('status_ativo')
            ? Contrato::STATUS_ATIVO
            : Contrato::STATUS_ENCERRADO;

        $contrato->setStatus($novoStatus);
        $em->flush();

        return $this->redirectToRoute('contrato_show', ['id' => $contrato->getId()]);
    }
}
