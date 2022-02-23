<?php

namespace Outlandish\Wpackagist\Controller;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Outlandish\Wpackagist\Entity\Package;
use Outlandish\Wpackagist\Entity\PackageRepository;
use Outlandish\Wpackagist\Entity\Plugin;
use Outlandish\Wpackagist\Entity\Theme;
use Outlandish\Wpackagist\Service;
use Pagerfanta\Doctrine\ORM\QueryAdapter;
use Pagerfanta\Pagerfanta;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\SearchType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;
use Outlandish\Wpackagist\Entity\Request as RequestLog;
use Outlandish\Wpackagist\Storage;

class MainController extends AbstractController
{
    /** @var FormFactoryInterface */
    private $formFactory;
    /** @var FormInterface|null */
    private $form;
    /** @var Storage\PackageStore */
    private $storage;

    public function __construct(FormFactoryInterface $formFactory, Storage\PackageStore $storage)
    {
        $this->formFactory = $formFactory;
        $this->storage = $storage;
    }

    /**
     * @Route("packages.json", name="json_index")
     */
    public function packageIndexJson(): Response
    {
        $response = new Response($this->storage->loadRoot());
        $response->headers->set('Content-Type', 'application/json');

        return $response;
    }

    /**
     * @Route("p/{provider}${hash}.json", name="json_provider", requirements={"hash"="[0-9a-f]{64}"})
     * @param string $provider
     * @param string $hash
     * @return Response
     */
    public function providerJson(string $provider, string $hash): Response
    {
        $data = $this->storage->loadProvider($provider, $hash);

        if (empty($data)) {
            throw new NotFoundHttpException();
        }

        $response = new Response($data);
        $response->headers->set('Content-Type', 'application/json');

        return $response;
    }

    /**
     * @Route("p/{dir}/{package}${hash}.json", name="json_package", requirements={"hash"="[0-9a-f]{64}"})
     * @param string $dir   Directory: wpackagist-plugin or wpackagist-theme.
     * @param string $package
     * @param string $hash
     * @return Response
     */
    public function packageJson(string $package, string $hash, string $dir): Response
    {
        $dir = str_replace('.', '', $dir);

        if (!in_array($dir, ['wpackagist-plugin', 'wpackagist-theme'], true)) {
            throw new BadRequestException('Unexpected package path');
        }

        $data = $this->storage->loadPackage("{$dir}/{$package}", $hash);

        if (empty($data)) {
            throw new NotFoundHttpException();
        }

        $response = new Response($data);
        $response->headers->set('Content-Type', 'application/json');

        return $response;
    }

    public function home(Request $request): Response
    {
        return $this->render('index.twig', [
            'title'      => 'WordPress Packagist: Manage your plugins and themes with Composer',
            'searchForm' => $this->getForm()->handleRequest($request)->createView(),
        ]);
    }

    public function search(Request $request, Connection $connection, EntityManagerInterface $entityManager): Response
    {
        $queryBuilder = new QueryBuilder($entityManager);

        $form = $this->getForm();
        $form->handleRequest($request);

        $data = $form->getData();
        $type = $data['type'] ?? null;
        $active = $data['active_only'] ?? false;
        $query = empty($data['q']) ? null : trim($data['q']);

        $data = [
            'title'              => "WordPress Packagist: Search packages",
            'searchForm'         => $form->createView(),
            'currentPageResults' => '',
            'error'              => '',
        ];

        // TODO move search query logic to PackageRepository.
        $queryBuilder
            ->select('p');

        switch ($type) {
            case 'theme':
                $queryBuilder->from(Theme::class, 'p');
                break;
            case 'plugin':
                $queryBuilder->from(Plugin::class, 'p');
                break;
            default:
                $queryBuilder->from(Package::class, 'p');
        }

        switch ($active) {
            case 1:
                $queryBuilder->andWhere('p.isActive = true');
                break;

            default:
                $queryBuilder->addOrderBy('p.isActive', 'DESC');
                break;
        }

        if (!empty($query)) {
            $queryBuilder
                ->andWhere($queryBuilder->expr()->orX(
                    $queryBuilder->expr()->like('p.name', ':name'),
                    $queryBuilder->expr()->like('p.displayName', ':name')
                ))
                ->addOrderBy('p.name', 'ASC')
                ->setParameter('name', "%{$query}%");
        } else {
            $queryBuilder
                ->addOrderBy('p.lastCommitted', 'DESC');
        }

        $adapter    = new QueryAdapter($queryBuilder);
        $pagerfanta = new Pagerfanta($adapter);
        $pagerfanta->setMaxPerPage(30);
        $pagerfanta->setCurrentPage($request->query->get('page', 1));

        $data['pager']              = $pagerfanta;
        $data['currentPageResults'] = $pagerfanta->getCurrentPageResults();

        return $this->render('search.twig', $data);
    }

    public function update(
        Request $request,
        Connection $connection,
        EntityManagerInterface $entityManager,
        LoggerInterface $logger,
        Service\Update $updateService,
        Storage\PackageStore $storage,
        Service\Builder $builder
    ): Response
    {
        $storage->prepare(true);

        // first run the update command
        $name = $request->get('name');
        if (!trim($name)) {
            return new Response('Invalid Request',400);
        }

        /** @var PackageRepository $packageRepo */
        $packageRepo = $entityManager->getRepository(Package::class);

        $package = $packageRepo->findOneBy(['name' => $name]);
        if (!$package) {
            return new Response('Not Found',404);
        }

        $requestCount = $entityManager->getRepository(RequestLog::class)
            ->getRequestCountByIp($request->getClientIp(), 0);
        if ($requestCount > 5) {
            return new Response('Too many requests. Try again in an hour.', 403);
        }

        $safeName = $package->getName();
        $package = $updateService->updateOne($logger, $safeName);
        if ($package && !empty($package->getVersions()) && $package->isActive()) {
            // update just the package
            $builder->updatePackage($package);
            $storage->persist();

            // then update the corresponding group and the root provider, using all packages in the same group
            $group = $package->getProviderGroup();
            $groupPackageNames = $packageRepo->findActivePackageNamesByGroup($group);
            $builder->updateProviderGroup($group, $groupPackageNames);
            $storage->persist();
            $builder->updateRoot();
        }

        // updates are complete, so persist everything
        $storage->persist(true);

        return new RedirectResponse('/search?q=' . $safeName);
    }

    private function getForm(): FormInterface
    {
        if (!isset($this->form)) {
            $this->form = $this->formFactory
                // A named builder with blank name enables not having a param prefix like `formName[fieldName]`.
                ->createNamedBuilder('', FormType::class, null, ['csrf_protection' => false])
                ->setAction('search')
                ->setMethod('GET')
                ->add('q', SearchType::class)
                ->add('type', ChoiceType::class, [
                    'choices' => [
                        'All packages' => 'any',
                        'Plugins' => 'plugin',
                        'Themes' => 'theme',
                    ],
                ])
                ->add('search', SubmitType::class)
                ->getForm();
        }

        return $this->form;
    }
}
