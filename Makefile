build:
	php composer.phar install
	php phar-composer.phar build .
	chmod +x ninespot.phar
.PHONY: build

clean:
	rm -rf vendor
	rm -rf ninespot.phar
.PHONY: clean

update:
	php composer.phar update
.PHONY: update
