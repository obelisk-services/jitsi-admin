<?php

namespace App\Controller;

use App\Entity\Rooms;
use App\Entity\Server;
use App\Entity\User;
use App\Form\Type\JoinViewType;
use App\Service\PexelService;
use App\Service\RoomService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

use Symfony\Contracts\Translation\TranslatorInterface;

class JoinController extends AbstractController
{
    private $parameterBag;

    public function __construct(ParameterBagInterface $parameterBag)
    {
        $this->parameterBag = $parameterBag;
    }

    /**
     * @Route("/join/{slug}", name="join_index")
     * @Route("/join/{slug}/{uid}", name="join_index_uid")
     * @Route("/join", name="join_index_no_slug")
     */
    public function index(Request $request, TranslatorInterface $translator, RoomService $roomService, $slug = null, $uid = null)
    {
        $data = array();
        $server = $this->getDoctrine()->getRepository(Server::class)->findOneBy(['slug' => $slug]);
        // dataStr wird mit den Daten uid und email encoded übertragen. Diese werden daraufhin als Vorgaben in das Formular eingebaut
        $dataStr = $request->get('data');
        $snack = $request->get('snack');
        $color = 'success';
        $dataAll = base64_decode($dataStr);
        $data = array();
        $room = null;

        parse_str($dataAll, $data);
        if ($request->cookies->get('name')) {
            $data['name'] = $request->cookies->get('name');
        }

        if (isset($data['email']) && isset($data['uid'])) {
            $room = $this->getDoctrine()->getRepository(Rooms::class)->findOneBy(['uid' => $data['uid']]);
            $user = $this->getDoctrine()->getRepository(User::class)->findOneBy(['email' => $data['email']]);

            //If the room ID is correct set and the room exists
            if ($this->onlyWithUserAccount($room, $user)) {
                return $this->redirectToRoute('room_join', ['room' => $room->getId(), 't' => 'b']);
            }
        } else {
            $snack = $translator->trans('Zugangsdaten in das Formular eingeben');
        }

        if ($this->parameterBag->get('laF_onlyRegisteredParticipents') == 1) {
            return $this->redirectToRoute('dashboard');
        }

        $form = $this->createForm(JoinViewType::class, $data);
        $form->handleRequest($request);
        $errors = array();

        if ($room) {
            $now = new \DateTime();
            $start = null;
            if($room->getStart()){
                $start = (clone $room->getStart())->modify('-30min');
            }


            if (
                ($start && $start < $now && $room->getEnddate() > $now)
                || $this->getUser() == $room->getModerator()
                || ($room->getPersistantRoom())
            ) {
                if ($form->isSubmitted() && $form->isValid()) {
                    $search = $form->getData();
                    $room = $this->getDoctrine()->getRepository(Rooms::class)->findOneBy(['uid' => $search['uid']]);
                    $user = $this->getDoctrine()->getRepository(User::class)->findOneBy(['email' => $search['email']]);

                    if ($form->get('joinApp')->isClicked()) {
                        $type = 'a';
                    } elseif ($form->get('joinBrowser')->isClicked()) {
                        $type = 'b';
                    }

                    if (
                        count($errors) == 0
                        && $room
                        && $user
                        && (in_array($user, $room->getUser()->toarray()) || $room->getTotalOpenRooms())
                    ) {
                        if ($this->onlyWithUserAccount($room, $user) || $this->userAccountLogin($room, $user)) {
                            return $this->redirectToRoute('room_join', ['room' => $room->getId(), 't' => $type]);
                        }

                        $url = $roomService->join($room, $user, $type, $search['name']);

                        $res = $this->redirect($url);
                        $res->headers->setCookie(new Cookie('name', $search['name'], (new \DateTime())->modify('+365 days')));
                        return $res;

                    }
                    if($room->getTotalOpenRooms()){
                        $url = $this->generateUrl('room_waiting',array('name'=>$search['name'],'uid'=>$room->getUid(),'type'=>$type));
                        $res = $this->redirect($url);
                        $res->headers->setCookie(new Cookie('name', $search['name'], (new \DateTime())->modify('+365 days')));
                        return $res;
                    }
                    $snack = $translator->trans('Konferenz nicht gefunden. Zugangsdaten erneut eingeben');
                }
            } else {
                try {
                    $snack = $translator->trans('Der Beitritt ist nur von {from} bis {to} möglich',
                        array(
                            '{from}' => $start->format('d.m.Y H:i'),
                            '{to}' => $room->getEnddate()->format('d.m.Y H:i')
                        )
                    );
                    $color = 'danger';
                }catch (\Exception $exception){

                }

            }
        }

        return $this->render('join/index.html.twig', [
            'color' => $color,
            'form' => $form->createView(),
            'snack' => $snack,
            'server' => $server,


        ]);
    }

    /**
     * function onlyWithUserAccount
     * Return if only users with account can join the conference
     * @return boolean
     * @author Andreas Holzmann
     */
    function onlyWithUserAccount(?Rooms $room)
    {
        if ($room) {
            return $this->parameterBag->get('laF_onlyRegisteredParticipents') == 1 || //only registered Users globally set
                $room->getOnlyRegisteredUsers();
        }
        return false;
    }

    /**
     * function userAccountLogin
     * Return boolean if account must login to join the conference
     * @return boolean
     * @author Andreas Holzmann
     */
    function userAccountLogin(?Rooms $room, ?User $user)
    {
        if ($room) {
            return $user && $user->getKeycloakId() !== null; // Registered Users have to login before they can join the conference
        }
        return false;
    }
}
