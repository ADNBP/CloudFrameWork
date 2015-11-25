<?php
    namespace CloudFramework\Service\DataStore;

    use CloudFramework\Helpers\Response;
    use CloudFramework\Patterns\Singleton;
    use CloudFramework\Service\DataStore\Message\Schema;

    class DataStore extends Singleton
    {
        use Response;

        /**
         * @AutoWired
         * @var \Google_Client $client
         */
        protected $client;
        /**
         * @var \Google_Service_DataStore $store
         */
        protected $store;
        /**
         * @var array $config
         */
        protected $config;
        /**
         * @var string $dataset_id
         */
        protected $dataset_id;

        public function __construct($config = array())
        {
            $this->config = $config;
            //FIXME automatic behaviors
            $this->preInit();
            $this->postInit();
        }

        public function preInit()
        {
            if (NULL === $this->config) {
                throw new \Exception('Need config to initialize datastore');
            }
            if (!array_key_exists('service-account-name', $this->config)) {
                throw new \Exception('Parameter "service-account-name" is required to initialize service');
            }
            if (!array_key_exists('private-key', $this->config)) {
                throw new \Exception('Parameter "private-key" is required to initialize service');
            }
            if (!array_key_exists('application-id', $this->config)) {
                throw new \Exception('Parameter "application-id" is required to initialize service');
            }
            if (array_key_exists('dataset', $this->config)) {
                $this->dataset_id = $this->config['dataset'];
            }
            $this->client = new \Google_Client();
        }

        public function postInit()
        {
            $this->client->setApplicationName($this->config['application-id']);
            $this->client->setAssertionCredentials(new \Google_Auth_AssertionCredentials(
                $this->config['service-account-name'],
                ["https://www.googleapis.com/auth/cloud-platform", "https://www.googleapis.com/auth/datastore", "https://www.googleapis.com/auth/userinfo.email"],
                $this->config['private-key']));

            $this->store = new \Google_Service_Datastore($this->client);
        }

        /**
         * Setter
         * @param string $dataset
         */
        public function setDataset($dataset)
        {
            $this->dataset_id = $dataset;
        }

        /**
         * Checks all required configurations before send a request to GAE
         * @throws \Exception
         */
        private function checkConfig()
        {
            if(null === $this->dataset_id) {
                syslog(LOG_ERR, 'Misconfigured dataset, parameter datastore_id is required');
                throw new \Exception('Misconfigured dataset, parameter datastore_id is required');
            }
        }

        /**
         * @param \Google_Service_Datastore_RunQueryResponse $response
         * @param Schema $object
         *
         * @return Schema[]
         */
        private function mapSchemaList(\Google_Service_Datastore_RunQueryResponse $response, Schema $object)
        {
            $mappedResults = array();
            $results = $response->getBatch()->getEntityResults();
            if (count($results) > 0) {
                /** @var \Google_Service_Datastore_EntityResult $entityResult */
                foreach ($results as $entityResult) {
                    $model = $entityResult->getEntity();
                    $mappedResults[] = $object->hydrateFromEntity($model);
                }
            }
            return $mappedResults;
        }

        /**
         * Store results in MemCache
         * @param string $key
         * @param $data
         * @param int $time
         */
        private function storeInCache($key, $data, $time = 600)
        {
            $cache = new \Memcache();
            $cache->add($key, $data, 0, $time);
        }

        /**
         * Get results from cache
         * @param string $key
         *
         * @return array|string
         */
        private function getFromCache($key)
        {
            $cache = new \Memcache();
            return $cache->get($key);
        }

        /**
         * @param Schema $object
         * @param array $optParams
         *
         * @return bool
         * @throws \Exception
         */
        public function save(Schema $object, $optParams = [])
        {
            $this->checkConfig();
            /** @var \Google_Service_Datastore_CommitRequest $body */
            $body = $object->createRequestMessage();
            $result = $this->store->datasets->commit($this->dataset_id, $body, $optParams);
            //$this->storeInCache($object->generateHash(), $object);
            return $result->getMutationResult()->getIndexUpdates() > 0;
        }

        /**
         * @param Schema $object
         * @param array $optParams
         *
         * @return Schema[]
         * @throws \Exception
         */
        public function search(Schema $object, $optParams = [])
        {
            $this->checkConfig();
            $mappedResult = $this->getFromCache($object->generateHash());
            //if(!$mappedResult) {
                /** @var \Google_Service_Datastore_LookupRequest $query */
                $gql_query = new \Google_Service_Datastore_GqlQuery();
                $query = "SELECT * FROM {$object->getKind()} WHERE " . implode(' AND ', $object->generateFilteredQuery());
                $gql_query->setQueryString($query);
                $gql_query->setAllowLiteral(true);

                $req = new \Google_Service_Datastore_RunQueryRequest();
                $req->setGqlQuery($gql_query);
                $result = $this->store->datasets->runQuery($this->dataset_id, $req, $optParams);
                $mappedResult = $this->mapSchemaList($result, $object);
                $this->storeInCache($object->generateHash(), $mappedResult);
            //}
            return $mappedResult;
        }

        public function runQuery(\Google_Service_Datastore_RunQueryRequest $postBody, $optParams = [])
        {
            $this->checkConfig();
            return $this->store->datasets->runQuery($this->dataset_id, $postBody, $optParams);
        }

    }